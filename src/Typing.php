<?php

/**
 * Typing Indicator plugin for GLPI - storage and business logic.
 *
 * Each user that is typing sends a heartbeat every ~2.5 s. A heartbeat is
 * considered alive for TTL seconds, so a closed browser tab disappears on its
 * own without any cron task.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Typingindicator;

use DBConnection;
use Migration;
use User;

class Typing
{
    public const TABLE = 'glpi_plugin_typingindicator_typings';

    /** Seconds a heartbeat stays valid */
    public const TTL = 8;

    /** Delay between two polls, in milliseconds (sent to the browser) */
    public const POLL_INTERVAL = 3000;

    /** Rows older than this (seconds) are purged */
    public const PURGE_AFTER = 120;

    public const ITEMTYPES = ['Ticket', 'Problem', 'Change'];

    public const KINDS = ['followup', 'task', 'solution'];

    // ------------------------------------------------------------------
    // Install / uninstall
    // ------------------------------------------------------------------

    public static function install(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

        $migration->displayMessage('Creating ' . self::TABLE);

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        $query = "CREATE TABLE `" . self::TABLE . "` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `kind` varchar(20) NOT NULL DEFAULT 'followup',
            `is_private` tinyint NOT NULL DEFAULT '0',
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`,`items_id`,`users_id`),
            KEY `users_id` (`users_id`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        self::runQuery($query);
    }

    public static function uninstall(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $migration->dropTable(self::TABLE);
        }
    }

    /**
     * DBmysql::query() was deprecated in GLPI 10.0.11 and removed later;
     * doQuery() does not exist before 10.0.11.
     */
    private static function runQuery(string $query): void
    {
        global $DB;

        if (method_exists($DB, 'doQuery')) {
            $DB->doQuery($query);
        } else {
            $DB->queryOrDie($query, 'Typing indicator: unable to create table');
        }
    }

    // ------------------------------------------------------------------
    // Validation helpers
    // ------------------------------------------------------------------

    public static function isAllowedItemtype(string $itemtype): bool
    {
        return in_array($itemtype, self::ITEMTYPES, true);
    }

    public static function normalizeKind(string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? $kind : 'followup';
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public static function ping(string $itemtype, int $items_id, int $users_id, string $kind, bool $is_private): void
    {
        global $DB;

        // A person types in one place at a time: drop their rows on other
        // items so the table never holds more than one row per user.
        $DB->delete(self::TABLE, [
            'users_id' => $users_id,
            'NOT'      => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ]);

        $DB->updateOrInsert(
            self::TABLE,
            [
                'kind'       => self::normalizeKind($kind),
                'is_private' => $is_private ? 1 : 0,
                'date_mod'   => date('Y-m-d H:i:s'),
            ],
            [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $users_id,
            ]
        );
    }

    public static function stop(string $itemtype, int $items_id, int $users_id): void
    {
        global $DB;

        $DB->delete(self::TABLE, [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'users_id' => $users_id,
        ]);
    }

    /**
     * Returns the list of other users currently typing on the item.
     *
     * $visibility tells, per kind, what the viewer may know about:
     *   ['followup' => ['public' => bool, 'private' => bool], 'task' => [...], 'solution' => [...]]
     *
     * @return array<int, array{id:int, name:string, kind:string}>
     */
    public static function getTypers(string $itemtype, int $items_id, int $viewer_id, array $visibility): array
    {
        global $DB;

        // Light housekeeping (about 1 poll in 20)
        if (random_int(1, 20) === 1) {
            $DB->delete(self::TABLE, [
                'date_mod' => ['<', date('Y-m-d H:i:s', time() - self::PURGE_AFTER)],
            ]);
        }

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'NOT'      => ['users_id' => $viewer_id],
                'date_mod' => ['>=', date('Y-m-d H:i:s', time() - self::TTL)],
            ],
            'ORDER' => 'date_mod ASC',
        ]);

        $typers = [];
        foreach ($iterator as $row) {
            $kind  = self::normalizeKind((string) $row['kind']);
            $scope = ((int) $row['is_private'] === 1) ? 'private' : 'public';
            if (empty($visibility[$kind][$scope])) {
                continue;
            }
            $user = new User();
            if (
                !$user->getFromDB((int) $row['users_id'])
                || (int) ($user->fields['is_active'] ?? 1) !== 1
                || (int) ($user->fields['is_deleted'] ?? 0) !== 0
            ) {
                continue;
            }
            $typers[] = [
                'id'   => (int) $row['users_id'],
                'name' => self::plainText((string) $user->getFriendlyName()),
                'kind' => $kind,
            ];
        }

        // Stable, alphabetical order: names must not swap places on every
        // heartbeat ("A and B are typing" / "B and A are typing").
        usort($typers, static function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']);
        });

        return $typers;
    }

    /**
     * Returns raw text (never HTML). GLPI 10 stores strings HTML-encoded in
     * the database, GLPI 11+ stores them raw. The browser always inserts the
     * result with textContent and PHP always escapes it, so raw is the safe,
     * correct form in both cases.
     */
    public static function plainText(string $value): string
    {
        if (version_compare(GLPI_VERSION, '11.0.0-dev', '<')) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Strip control characters
        return (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }

    /**
     * Builds the human readable sentence (translatable, plugin domain).
     *
     * @param array<int, array{name:string, kind:string}> $typers
     */
    public static function buildMessage(array $typers): string
    {
        $names = array_column($typers, 'name');
        $count = count($names);

        switch ($count) {
            case 0:
                return '';
            case 1:
                return sprintf(__('%s typing…', 'typingindicator'), $names[0]);
            case 2:
                return sprintf(__('%1$s & %2$s typing…', 'typingindicator'), $names[0], $names[1]);
            case 3:
                return sprintf(__('%1$s, %2$s & %3$s typing…', 'typingindicator'), $names[0], $names[1], $names[2]);
            default:
                return sprintf(__('%1$s, %2$s & %3$d others typing…', 'typingindicator'), $names[0], $names[1], $count - 2);
        }
    }
}
