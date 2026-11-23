<?php

/**
 * Typing Indicator plugin for GLPI - administrator configuration page.
 * Reachable from Setup > Plugins > Typing indicator (wrench icon).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Typingindicator\Settings;

// GLPI 11+ boots the kernel before legacy scripts; GLPI 10 needs includes.php.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

// Only administrators allowed to change the general configuration.
Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    Settings::save($_POST);
    Session::addMessageAfterRedirect(__('Typing indicator settings saved', 'typingindicator'), true, INFO);
    Html::back();
    return;
}

Html::header(__('Typing indicator', 'typingindicator'), $_SERVER['PHP_SELF'], 'config', 'plugin');
Settings::showForm();
Html::footer();
