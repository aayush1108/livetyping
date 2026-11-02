<?php

declare(strict_types=1);

/**
 * Live typing indicator: shows who else is typing a reply on the ticket you are viewing.
 *
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Livetyping\Access;
use GlpiPlugin\Livetyping\Cleanup;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

define('PLUGIN_LIVETYPING_VERSION', '1.1.0');
define('PLUGIN_LIVETYPING_MIN_GLPI', '11.0.6');
define('PLUGIN_LIVETYPING_MIN_PHP', '8.2');

/**
 * Plugin description (shown in Setup > Plugins).
 */
function plugin_version_livetyping(): array
{
    return [
        'name'         => 'Live Typing Indicator',
        'version'      => PLUGIN_LIVETYPING_VERSION,
        'author'       => 'Aayush',
        'license'      => 'GPLv3+',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_LIVETYPING_MIN_GLPI],
            'php'  => ['min' => PLUGIN_LIVETYPING_MIN_PHP],
        ],
    ];
}

function plugin_livetyping_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_LIVETYPING_MIN_GLPI, '<')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_LIVETYPING_MIN_GLPI;
        return false;
    }
    if (version_compare(PHP_VERSION, PLUGIN_LIVETYPING_MIN_PHP, '<')) {
        echo 'This plugin requires PHP >= ' . PLUGIN_LIVETYPING_MIN_PHP;
        return false;
    }
    return true;
}

function plugin_livetyping_check_config($verbose = false): bool
{
    return true;
}

/**
 * Plugin initialisation (runs on every request while the plugin is active).
 * Must never throw and must stay cheap: the browser script is only added on
 * ticket pages, and only when the plugin is enabled for the user's profile.
 */
function plugin_init_livetyping(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['livetyping'] = true;

    if (!Plugin::isPluginActive('livetyping')) {
        return;
    }

    // Remove the plugin's own rows when a ticket, a user or a profile is purged
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['livetyping'] = [
        'Ticket'  => [Cleanup::class, 'purgeTicket'],
        'User'    => [Cleanup::class, 'purgeUser'],
        'Profile' => [Cleanup::class, 'purgeProfile'],
    ];

    // Admin page (Setup > Plugins > Live Typing Indicator): rights are checked on the page itself
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['livetyping'] = 'front/config.php';

    // Browser script + style: ticket pages only (cheap string test first, no database access
    // on any other page), then the cached, never-throwing profile check.
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (str_contains($uri, '/front/ticket.form.php') && Access::isActiveForSession()) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['livetyping'] = ['js/typing.js'];
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['livetyping']        = ['css/typing.css'];
    }
}
