<?php

/**
 * Typing Indicator plugin for GLPI - administrator settings.
 *
 * Only users allowed to update the GLPI general configuration (Super-Admin)
 * can open or change these settings. Regular users have nothing to configure:
 * they only see the indicator (when allowed).
 *
 * Access rule (evaluated separately for "see" and "broadcast"):
 *   allowed = plugin enabled
 *             AND (active profile is selected OR user belongs to a selected group)
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Typingindicator;

use Config;
use Html;
use Session;

class Settings
{
    public const CONTEXT = 'plugin:typingindicator';

    public const CAP_SEE       = 'see';
    public const CAP_BROADCAST = 'broadcast';

    private const KEYS = ['enabled', 'see_profiles', 'see_groups', 'broadcast_profiles', 'broadcast_groups'];

    /** @var array|null request cache */
    private static $cache = null;

    // ------------------------------------------------------------------
    // Read / write
    // ------------------------------------------------------------------

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = Config::getConfigurationValues(self::CONTEXT, self::KEYS);

        self::$cache = [
            'enabled'            => (int) ($raw['enabled'] ?? 1) === 1,
            'see_profiles'       => self::decodeIds($raw['see_profiles'] ?? '[]'),
            'see_groups'         => self::decodeIds($raw['see_groups'] ?? '[]'),
            'broadcast_profiles' => self::decodeIds($raw['broadcast_profiles'] ?? '[]'),
            'broadcast_groups'   => self::decodeIds($raw['broadcast_groups'] ?? '[]'),
        ];
        return self::$cache;
    }

    public static function save(array $input): void
    {
        Config::setConfigurationValues(self::CONTEXT, [
            'enabled'            => empty($input['enabled']) ? 0 : 1,
            'see_profiles'       => self::encodeIds($input['see_profiles'] ?? []),
            'see_groups'         => self::encodeIds($input['see_groups'] ?? []),
            'broadcast_profiles' => self::encodeIds($input['broadcast_profiles'] ?? []),
            'broadcast_groups'   => self::encodeIds($input['broadcast_groups'] ?? []),
        ]);
        self::$cache = null;
    }

    /**
     * Default settings on first install: plugin enabled, only profiles that can
     * update the general configuration (Super-Admin) get access.
     */
    public static function installDefaults(): void
    {
        global $DB;

        $existing = Config::getConfigurationValues(self::CONTEXT, ['enabled']);
        if (array_key_exists('enabled', $existing)) {
            return; // keep the administrator's choices on reinstall/upgrade
        }

        $admin_profiles = [];
        $iterator = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config'],
        ]);
        foreach ($iterator as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                $admin_profiles[] = (int) $row['profiles_id'];
            }
        }

        self::save([
            'enabled'            => 1,
            'see_profiles'       => $admin_profiles,
            'broadcast_profiles' => $admin_profiles,
        ]);
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, self::KEYS);
    }

    // ------------------------------------------------------------------
    // Access checks (current session)
    // ------------------------------------------------------------------

    public static function userCan(string $cap): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }

        $settings = self::get();
        if (!$settings['enabled']) {
            return false;
        }

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (in_array($profiles_id, $settings[$cap . '_profiles'], true)) {
            return true;
        }

        $user_groups = array_map('intval', (array) ($_SESSION['glpigroups'] ?? []));
        return count(array_intersect($user_groups, $settings[$cap . '_groups'])) > 0;
    }

    public static function canSee(): bool
    {
        return self::userCan(self::CAP_SEE);
    }

    public static function canBroadcast(): bool
    {
        return self::userCan(self::CAP_BROADCAST);
    }

    public static function canConfigure(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    // ------------------------------------------------------------------
    // Configuration form
    // ------------------------------------------------------------------

    public static function showForm(): void
    {
        global $DB;

        $settings = self::get();

        $profiles = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name']) as $row) {
            $profiles[(int) $row['id']] = Typing::plainText((string) $row['name']);
        }
        $groups = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename']) as $row) {
            $groups[(int) $row['id']] = Typing::plainText((string) $row['completename']);
        }

        $e = [self::class, 'e'];

        echo "<div class='container-fluid'>";
        echo "<form method='post' action=''>";
        echo "<div class='card'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-keyboard me-2'></i>"
            . $e(__('Typing indicator settings', 'typingindicator')) . "</h3></div>";
        echo "<div class='card-body'>";

        // Enable switch
        echo "<div class='form-check form-switch mb-4'>";
        echo "<input type='hidden' name='enabled' value='0'>";
        echo "<input class='form-check-input' type='checkbox' role='switch' id='ti_enabled' name='enabled' value='1'"
            . ($settings['enabled'] ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='ti_enabled'>" . $e(__('Enable the typing indicator', 'typingindicator')) . "</label>";
        echo "</div>";

        echo "<table class='table table-bordered align-middle'>";
        echo "<thead><tr><th style='width:22%'></th><th>" . $e(__('Profiles', 'typingindicator')) . "</th><th>"
            . $e(__('Groups', 'typingindicator')) . "</th></tr></thead><tbody>";

        echo "<tr><th>" . $e(__('Can see who is typing', 'typingindicator'))
            . "<div class='text-muted small fw-normal'>" . $e(__('The indicator is displayed to these users.', 'typingindicator')) . "</div></th>";
        echo "<td>" . self::multiSelect('see_profiles', $profiles, $settings['see_profiles']) . "</td>";
        echo "<td>" . self::multiSelect('see_groups', $groups, $settings['see_groups']) . "</td></tr>";

        echo "<tr><th>" . $e(__('Is shown to others when typing', 'typingindicator'))
            . "<div class='text-muted small fw-normal'>" . $e(__('Typing activity of these users is announced to others.', 'typingindicator')) . "</div></th>";
        echo "<td>" . self::multiSelect('broadcast_profiles', $profiles, $settings['broadcast_profiles']) . "</td>";
        echo "<td>" . self::multiSelect('broadcast_groups', $groups, $settings['broadcast_groups']) . "</td></tr>";

        echo "</tbody></table>";

        echo "<div class='alert alert-info mb-0'>"
            . $e(__('A user is allowed when their active profile OR one of their groups is selected. Leave both empty to allow nobody. Private followups and tasks are only announced to users who can see them. Users cannot change these settings; only administrators with the right to update the general configuration can.', 'typingindicator'))
            . "</div>";

        echo "</div>"; // card-body
        echo "<div class='card-footer text-end'>";
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo "</div>";
        echo "</div>"; // card
        if (version_compare(GLPI_VERSION, '12.0.0-dev', '<')) {
            Html::closeForm(); // GLPI 10/11: adds the CSRF token field
        } else {
            echo '</form>';    // GLPI 12+: CSRF checked from browser headers, no token field
        }
        echo "</div>";

        // Progressive enhancement with GLPI's bundled select2 (escapes text by default).
        echo Html::scriptBlock(
            "$(function () { if ($.fn.select2) { $('select.typingindicator-multiselect').select2({ width: '100%' }); } });"
        );
    }

    /**
     * Native multi-select whose labels and values are escaped here, so the
     * output is safe whatever the GLPI version.
     *
     * @param array<int, string> $options id => plain text label
     * @param int[]              $selected
     */
    private static function multiSelect(string $name, array $options, array $selected): string
    {
        // Field names are internal constants, never user input.
        $html = "<input type='hidden' name='" . self::e($name) . "[]' value='0'>"
            . "<select multiple size='6' class='form-select typingindicator-multiselect' name='"
            . self::e($name) . "[]'>";
        foreach ($options as $id => $label) {
            $html .= "<option value='" . (int) $id . "'"
                . (in_array((int) $id, $selected, true) ? ' selected' : '')
                . ">" . self::e($label) . "</option>";
        }
        return $html . "</select>";
    }

    /**
     * HTML-escapes a plain text value for element content and quoted attributes.
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function decodeIds($value): array
    {
        $ids = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
    }

    private static function encodeIds($value): string
    {
        return json_encode(self::decodeIds(is_array($value) ? $value : []));
    }
}
