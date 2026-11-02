<?php

declare(strict_types=1);

/**
 * Install / uninstall. The plugin only ever creates, reads and drops its own three tables.
 * It never modifies GLPI core tables, templates, notifications or settings.
 *
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

function plugin_livetyping_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // Who is typing on which ticket right now (short-lived rows).
    if (!$DB->tableExists('glpi_plugin_livetyping_states')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_livetyping_states` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `last_typed` int unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`tickets_id`, `users_id`),
            KEY `users_id` (`users_id`),
            KEY `last_typed` (`last_typed`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // The plugin's own settings (kept out of GLPI's glpi_configs table on purpose).
    if (!$DB->tableExists('glpi_plugin_livetyping_settings')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_livetyping_settings` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `value` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Profiles allowed to use the plugin (used when visibility is "selected").
    if (!$DB->tableExists('glpi_plugin_livetyping_profiles')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_livetyping_profiles` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `profiles_id` int {$sign} NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `profiles_id` (`profiles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Upgrade from 1.0.0: the states table gets an index on users_id (idempotent, only adds it if missing).
    $migration = new Migration(defined('PLUGIN_LIVETYPING_VERSION') ? PLUGIN_LIVETYPING_VERSION : '1.1.0');
    $migration->addKey('glpi_plugin_livetyping_states', 'users_id');
    $migration->executeMigration();

    return true;
}

function plugin_livetyping_uninstall(): bool
{
    global $DB;

    // Only the plugin's own tables are removed; nothing else in GLPI is touched.
    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_livetyping_states`');
    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_livetyping_settings`');
    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_livetyping_profiles`');

    return true;
}
