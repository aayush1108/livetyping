<?php

/**
 * Typing Indicator plugin for GLPI - install / uninstall hooks.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Typingindicator\Settings;
use GlpiPlugin\Typingindicator\Typing;

function plugin_typingindicator_install()
{
    $migration = new Migration(PLUGIN_TYPINGINDICATOR_VERSION);

    Typing::install($migration);
    Settings::installDefaults();

    // Clean up the profile right used by version 1.0.0 (replaced by Settings).
    if (countElementsInTable('glpi_profilerights', ['name' => 'plugin_typingindicator']) > 0) {
        ProfileRight::deleteProfileRights(['plugin_typingindicator']);
    }

    $migration->executeMigration();

    return true;
}

function plugin_typingindicator_uninstall()
{
    $migration = new Migration(PLUGIN_TYPINGINDICATOR_VERSION);

    Typing::uninstall($migration);
    Settings::uninstall();

    return true;
}
