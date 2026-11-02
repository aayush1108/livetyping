<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

use Session;

/**
 * Who can use the plugin: all profiles, or only the profiles an admin selected.
 * Uses the user's ACTIVE profile, like the rest of GLPI's rights.
 */
final class Access
{
    public const TABLE         = 'glpi_plugin_livetyping_profiles';
    public const MODE_ALL      = 'all';
    public const MODE_SELECTED = 'selected';

    private const CACHE_SECONDS = 300;

    /** @var array<int, int>|null profiles_id => profiles_id */
    private static ?array $allowed = null;

    public static function normalizeMode(mixed $value): ?string
    {
        return Util::choice($value, [self::MODE_ALL, self::MODE_SELECTED]);
    }

    public static function getMode(): string
    {
        return Setting::get(Setting::VISIBILITY) === self::MODE_SELECTED
            ? self::MODE_SELECTED
            : self::MODE_ALL;
    }

    /**
     * @return array<int, int> profiles_id => profiles_id
     */
    public static function getAllowedProfileIds(): array
    {
        global $DB;

        if (self::$allowed === null) {
            $ids = [];
            foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => self::TABLE]) as $row) {
                $ids[(int) $row['profiles_id']] = (int) $row['profiles_id'];
            }
            self::$allowed = $ids;
        }

        return self::$allowed;
    }

    public static function isProfileAllowed(int $profiles_id): bool
    {
        if ($profiles_id <= 0) {
            return false;
        }

        return self::getMode() === self::MODE_ALL
            || isset(self::getAllowedProfileIds()[$profiles_id]);
    }

    /**
     * Authoritative check used by the AJAX endpoint: logged in, plugin enabled,
     * and the active profile is allowed.
     */
    public static function isUsable(): bool
    {
        if ((int) Session::getLoginUserID() <= 0 || !Setting::isEnabled()) {
            return false;
        }

        return self::isProfileAllowed((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0));
    }

    /**
     * Cheap check for plugin_init (adds the browser script): cached in the session for a few
     * minutes. Cosmetic only, the endpoint always re-checks with isUsable(). Never throws.
     */
    public static function isActiveForSession(): bool
    {
        try {
            $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            if ($profile <= 0 || (int) Session::getLoginUserID() <= 0) {
                return false;
            }

            $cache = $_SESSION['plugin_livetyping']['active'] ?? null;
            if (
                is_array($cache)
                && ($cache['profile'] ?? null) === $profile
                && is_int($cache['expires'] ?? null)
                && $cache['expires'] > time()
            ) {
                return (bool) ($cache['active'] ?? false);
            }

            $active = self::isUsable();
            $_SESSION['plugin_livetyping']['active'] = [
                'profile' => $profile,
                'active'  => $active,
                'expires' => time() + self::CACHE_SECONDS,
            ];

            return $active;
        } catch (\Throwable $e) {
            Util::log('Activation check skipped: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * All GLPI profiles, for the admin page.
     *
     * @return array<int, array{id: int, name: string, interface: string}>
     */
    public static function getAllProfiles(): array
    {
        global $DB;

        $profiles = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => ['name']]) as $row) {
            $profiles[(int) $row['id']] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'interface' => (string) $row['interface'],
            ];
        }
        return $profiles;
    }

    /**
     * Must be called inside the caller's transaction/try block: errors are not swallowed.
     * Profile ids that do not exist are ignored.
     *
     * @param int[] $profile_ids
     */
    public static function save(string $mode, array $profile_ids): void
    {
        global $DB;

        if (self::normalizeMode($mode) === null) {
            throw new \InvalidArgumentException('Invalid visibility mode');
        }

        $valid = array_keys(array_intersect_key(self::getAllProfiles(), array_flip($profile_ids)));

        Setting::set(Setting::VISIBILITY, $mode);
        $DB->delete(self::TABLE, ['profiles_id' => ['>', 0]]);
        foreach ($valid as $profiles_id) {
            $DB->insert(self::TABLE, ['profiles_id' => $profiles_id]);
        }

        self::resetCache();
    }

    public static function resetCache(): void
    {
        self::$allowed = null;
        Setting::resetCache();
    }
}
