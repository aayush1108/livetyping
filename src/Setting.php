<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

/**
 * The plugin's own settings, stored in the plugin's own table.
 * Only known names and valid values are accepted.
 */
final class Setting
{
    public const TABLE = 'glpi_plugin_livetyping_settings';

    public const ENABLED      = 'enabled';
    public const POLL_SECONDS = 'poll_seconds';
    public const VISIBILITY   = 'visibility';

    /** Refresh intervals an admin can choose (seconds). */
    public const POLL_CHOICES = [3, 5, 10, 15, 30];

    private const DEFAULTS = [
        self::ENABLED      => '1',
        self::POLL_SECONDS => '5',
        self::VISIBILITY   => 'all',
    ];

    private static ?array $cache = null;

    public static function get(string $name): string
    {
        global $DB;

        if (self::$cache === null) {
            $cache = [];
            try {
                foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
                    $cache[(string) $row['name']] = (string) $row['value'];
                }
            } catch (\Throwable) {
                // table missing (files updated but plugin not yet upgraded): use defaults
            }
            self::$cache = $cache;
        }

        return self::$cache[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    /**
     * Must be called inside the caller's transaction/try block: errors are not swallowed.
     */
    public static function set(string $name, string $value): void
    {
        global $DB;

        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        if ($name === self::ENABLED && !in_array($value, ['0', '1'], true)) {
            throw new \InvalidArgumentException('Invalid value');
        }
        if ($name === self::POLL_SECONDS && !in_array($value, array_map('strval', self::POLL_CHOICES), true)) {
            throw new \InvalidArgumentException('Invalid value');
        }
        if ($name === self::VISIBILITY && !in_array($value, [Access::MODE_ALL, Access::MODE_SELECTED], true)) {
            throw new \InvalidArgumentException('Invalid value');
        }

        $DB->delete(self::TABLE, ['name' => $name]);
        $DB->insert(self::TABLE, ['name' => $name, 'value' => $value]);
        self::$cache = null;
    }

    public static function isEnabled(): bool
    {
        return self::get(self::ENABLED) === '1';
    }

    public static function getPollSeconds(): int
    {
        $value = (int) self::get(self::POLL_SECONDS);
        return in_array($value, self::POLL_CHOICES, true) ? $value : 5;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
