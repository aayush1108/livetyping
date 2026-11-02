<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

/**
 * Who is typing on which ticket. One short-lived row per (ticket, user).
 * Every query is bounded and uses an index.
 */
final class State
{
    public const TABLE = 'glpi_plugin_livetyping_states';

    /** A user counts as "typing" if they signalled within this many seconds. */
    public const TTL_SECONDS = 10;

    /** At most this many typers are ever read for one ticket. */
    public const MAX_TYPERS = 5;

    /** Rows older than this are deleted. */
    private const PURGE_AFTER_SECONDS = 3600;

    /** The cleanup runs on about one write out of this many. */
    private const PURGE_ONE_IN = 25;

    /**
     * Record that the user is typing now. Safe against two requests racing.
     */
    public static function touch(int $tickets_id, int $users_id, int $now): void
    {
        global $DB;

        // A user can only ever hold the rows of the last TTL seconds: their expired rows are
        // removed first, so rows per user stay bounded by the rate limit (indexed on users_id).
        $DB->delete(self::TABLE, ['users_id' => $users_id, 'last_typed' => ['<', $now - self::TTL_SECONDS]]);

        $where  = ['tickets_id' => $tickets_id, 'users_id' => $users_id];
        $exists = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1])->count() > 0;

        if (!$exists) {
            try {
                $DB->insert(self::TABLE, $where + ['last_typed' => $now]);
                return;
            } catch (\Throwable) {
                // another request inserted the same row first: just update it below
            }
        }
        $DB->update(self::TABLE, ['last_typed' => $now], $where);
    }

    public static function clear(int $tickets_id, int $users_id): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['tickets_id' => $tickets_id, 'users_id' => $users_id]);
    }

    /**
     * Users (other than $exclude_users_id) who typed on this ticket in the last TTL seconds,
     * most recent first, at most MAX_TYPERS.
     *
     * @return int[]
     */
    public static function getTypers(int $tickets_id, int $exclude_users_id, int $now): array
    {
        global $DB;

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                ['last_typed' => ['>=', $now - self::TTL_SECONDS]],
                ['users_id'   => ['!=', $exclude_users_id]],
            ],
            'ORDER'  => ['last_typed DESC'],
            'LIMIT'  => self::MAX_TYPERS,
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /**
     * Occasional housekeeping, so the table never grows. Never throws.
     */
    public static function maybePurge(int $now): void
    {
        try {
            if (random_int(1, self::PURGE_ONE_IN) === 1) {
                self::purgeStale($now);
            }
        } catch (\Throwable $e) {
            Util::log('Purge skipped: ' . $e->getMessage());
        }
    }

    public static function purgeStale(int $now): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['last_typed' => ['<', $now - self::PURGE_AFTER_SECONDS]]);
    }
}
