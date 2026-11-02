<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

use Html;
use Plugin;
use Session;

/**
 * The admin page: saving (atomic) and rendering (everything escaped, no JavaScript).
 */
final class Admin
{
    /**
     * @param string|null $poll_seconds one of Setting::POLL_CHOICES as a string, null = unchanged
     * @param string|null $visibility   'all' | 'selected' | null (unchanged)
     * @param int[]       $profile_ids
     */
    public static function save(bool $enabled, ?string $poll_seconds, ?string $visibility, array $profile_ids): bool
    {
        global $DB;

        try {
            $DB->beginTransaction();
            Setting::set(Setting::ENABLED, $enabled ? '1' : '0');
            if ($poll_seconds !== null) {
                Setting::set(Setting::POLL_SECONDS, $poll_seconds);
            }
            if ($visibility !== null) {
                Access::save($visibility, $profile_ids);
            }
            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // nothing to roll back
            }
            Util::log('Admin save failed: ' . $e->getMessage());
            return false;
        } finally {
            Access::resetCache();
        }

        Util::log(sprintf(
            'Admin %d updated the plugin settings (enabled %s, refresh %s, visibility %s)',
            (int) Session::getLoginUserID(),
            $enabled ? 'yes' : 'no',
            $poll_seconds ?? 'unchanged',
            $visibility ?? 'unchanged'
        ));
        return true;
    }

    public static function showForm(): void
    {
        $action   = Plugin::getWebDir('livetyping') . '/front/config.php';
        $mode     = Access::getMode();
        $selected = Access::getAllowedProfileIds();
        $poll     = Setting::getPollSeconds();

        echo "<div class='container-fluid'><div class='card'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __s('Live typing indicator', 'livetyping') . '</h3></div>';
        echo "<form method='post' action='" . htmlescape($action) . "'>";
        echo "<div class='card-body'>";

        // Enable
        echo "<div class='mb-4'><label class='d-flex justify-content-between align-items-center'>";
        echo '<span><strong>' . __s('Enable the typing indicator', 'livetyping') . '</strong>'
            . "<br><span class='text-muted small'>"
            . __s('Shows "Name is typing…" on a ticket while a colleague is writing a reply, so two people do not answer at the same time.', 'livetyping')
            . '</span></span>';
        echo "<span class='form-check form-switch mb-0 ms-3'>"
            . "<input class='form-check-input' type='checkbox' name='enabled' value='1'" . (Setting::isEnabled() ? ' checked' : '') . '></span>';
        echo '</label></div>';

        // Refresh interval
        echo "<div class='mb-4'><label class='form-label' for='livetyping_poll'><strong>"
            . __s('Refresh interval', 'livetyping') . '</strong></label>';
        echo "<select class='form-select w-auto' id='livetyping_poll' name='poll_seconds'>";
        foreach (Setting::POLL_CHOICES as $seconds) {
            echo "<option value='" . (int) $seconds . "'" . ($seconds === $poll ? ' selected' : '') . '>'
                . htmlescape(sprintf(__('%d seconds', 'livetyping'), $seconds)) . '</option>';
        }
        echo '</select>';
        echo "<div class='text-muted small mt-1'>"
            . __s('How often an open ticket checks who is typing. Shorter is more "live" but adds server load (about one small request per open ticket per interval).', 'livetyping')
            . '</div></div>';

        // Who can use it
        echo "<div class='mb-2'>";
        echo "<h4 class='mb-2'>" . __s('Who can use this plugin', 'livetyping') . '</h4>';
        echo "<label class='form-check'><input class='form-check-input' type='radio' name='visibility' value='"
            . Access::MODE_ALL . "'" . ($mode === Access::MODE_ALL ? ' checked' : '') . '>'
            . "<span class='form-check-label'>" . __s('All profiles', 'livetyping') . '</span></label>';
        echo "<label class='form-check'><input class='form-check-input' type='radio' name='visibility' value='"
            . Access::MODE_SELECTED . "'" . ($mode === Access::MODE_SELECTED ? ' checked' : '') . '>'
            . "<span class='form-check-label'>" . __s('Only the selected profiles', 'livetyping') . '</span></label>';
        echo "<div class='row mt-2 ms-1'>";
        foreach (Access::getAllProfiles() as $profile) {
            $interface = $profile['interface'] === 'central'
                ? __s('Standard interface') : __s('Simplified interface');
            echo "<label class='form-check col-12 col-md-6 col-lg-4'>"
                . "<input class='form-check-input' type='checkbox' name='profiles[]' value='" . (int) $profile['id'] . "'"
                . (isset($selected[$profile['id']]) ? ' checked' : '') . '>'
                . "<span class='form-check-label'>" . htmlescape($profile['name'])
                . " <span class='text-muted small'>(" . $interface . ')</span></span></label>';
        }
        echo '</div>';
        echo "<p class='text-muted small mt-2 mb-0'>"
            . __s('Only people whose active profile is allowed send and see the indicator. People can only ever see who is typing on tickets they are allowed to view. Names follow GLPI\'s anonymisation setting for the simplified interface.', 'livetyping')
            . '</p></div>';

        echo '</div>'; // card-body
        echo "<div class='card-footer text-end'>"
            . "<button type='submit' name='save' value='1' class='btn btn-primary'>"
            . "<i class='ti ti-device-floppy me-1'></i>" . __s('Save') . '</button></div>';
        Html::closeForm();
        echo '</div></div>';
    }
}
