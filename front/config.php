<?php

declare(strict_types=1);

/**
 * Admin page: enable/disable, refresh interval and which profiles can use the plugin.
 *
 * Security: requires the "config" update right (checked first); CSRF token is enforced by GLPI for
 * every POST; submitted values are validated against whitelists; writes are rate-limited and atomic.
 *
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Livetyping\Access;
use GlpiPlugin\Livetyping\Admin;
use GlpiPlugin\Livetyping\Setting;
use GlpiPlugin\Livetyping\Util;

Session::checkRight('config', UPDATE);

$self_url = Plugin::getWebDir('livetyping') . '/front/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['save'])) {
    if (!Util::throttle('admin_save', 2)) {
        Session::addMessageAfterRedirect(__s('Please wait a moment before saving again.', 'livetyping'), false, ERROR);
        Html::redirect($self_url);
    }

    $saved = Admin::save(
        isset($_POST['enabled']),
        Util::choice($_POST['poll_seconds'] ?? null, array_map('strval', Setting::POLL_CHOICES)),
        Access::normalizeMode($_POST['visibility'] ?? null),
        Util::toIds($_POST['profiles'] ?? null)
    );
    if ($saved) {
        Session::addMessageAfterRedirect(__s('Settings saved.', 'livetyping'));
    } else {
        Session::addMessageAfterRedirect(__s('Settings could not be saved. Please try again.', 'livetyping'), false, ERROR);
    }
    Html::redirect($self_url);
}

Html::header(__('Live typing indicator', 'livetyping'), $_SERVER['PHP_SELF'], 'config', 'plugins');

Admin::showForm();

Html::footer();
