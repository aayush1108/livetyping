<?php

/**
 * -------------------------------------------------------------------------
 * Typing Indicator plugin for GLPI
 * -------------------------------------------------------------------------
 * Shows "Jane Doe is typing a comment…" below the "Answer / Add a followup"
 * area of Tickets, Problems and Changes while another user is writing.
 *
 * Compatible with GLPI 10.0.x, 11.x and 12.x.
 *
 * @license GPLv3+
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Typingindicator\Settings;

define('PLUGIN_TYPINGINDICATOR_VERSION', '1.3.0');
define('PLUGIN_TYPINGINDICATOR_MIN_GLPI', '10.0.0');
// "max" is exclusive in GLPI requirement checks.
define('PLUGIN_TYPINGINDICATOR_MAX_GLPI', '12.99.99');

/**
 * Plugin initialisation (called on every request).
 */
function plugin_init_typingindicator()
{
    global $PLUGIN_HOOKS;

    // GLPI 10 needs this flag; GLPI 11+ ignores it (CSRF is always enforced).
    $PLUGIN_HOOKS['csrf_compliant']['typingindicator'] = true;

    if (!Plugin::isPluginActive('typingindicator')) {
        return;
    }

    // Settings page (Setup > Plugins > wrench icon), administrators only.
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['typingindicator'] = 'front/config.form.php';
    }

    // Assets are only sent to users allowed to see or broadcast.
    if (Settings::canSee() || Settings::canBroadcast()) {
        // GLPI 11+ serves the plugin "public/" directory at the plugin root URL,
        // GLPI 10 serves the whole plugin directory.
        $prefix = version_compare(GLPI_VERSION, '11.0.0-dev', '>=') ? '' : 'public/';

        $PLUGIN_HOOKS['add_javascript']['typingindicator'][] = $prefix . 'js/typingindicator.js';
        $PLUGIN_HOOKS['add_css']['typingindicator'][]        = $prefix . 'css/typingindicator.css';
    }
}

/**
 * Plugin metadata.
 */
function plugin_version_typingindicator()
{
    return [
        'name'         => __('Typing indicator', 'typingindicator'),
        'version'      => PLUGIN_TYPINGINDICATOR_VERSION,
        'author'       => 'Typing Indicator contributors',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TYPINGINDICATOR_MIN_GLPI,
                'max' => PLUGIN_TYPINGINDICATOR_MAX_GLPI,
            ],
            'php'  => [
                'min' => '7.4',
            ],
        ],
    ];
}

/**
 * Requirements are declared in plugin_version_typingindicator().
 */
function plugin_typingindicator_check_prerequisites()
{
    return true;
}

function plugin_typingindicator_check_config($verbose = false)
{
    return true;
}
