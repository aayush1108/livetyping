<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

use Profile;
use Ticket;
use User;

/**
 * Keeps the plugin's own tables tidy when GLPI purges a ticket, a user or a profile.
 * Touches only the plugin's tables and never throws into GLPI.
 */
final class Cleanup
{
    public static function purgeTicket($item): void
    {
        global $DB;

        if (!($item instanceof Ticket) || (int) $item->getID() <= 0) {
            return;
        }
        try {
            $DB->delete(State::TABLE, ['tickets_id' => (int) $item->getID()]);
        } catch (\Throwable $e) {
            Util::log('Ticket cleanup skipped: ' . $e->getMessage());
        }
    }

    public static function purgeUser($item): void
    {
        global $DB;

        if (!($item instanceof User) || (int) $item->getID() <= 0) {
            return;
        }
        try {
            $DB->delete(State::TABLE, ['users_id' => (int) $item->getID()]);
        } catch (\Throwable $e) {
            Util::log('User cleanup skipped: ' . $e->getMessage());
        }
    }

    public static function purgeProfile($item): void
    {
        global $DB;

        if (!($item instanceof Profile) || (int) $item->getID() <= 0) {
            return;
        }
        try {
            $DB->delete(Access::TABLE, ['profiles_id' => (int) $item->getID()]);
            Access::resetCache();
        } catch (\Throwable $e) {
            Util::log('Profile cleanup skipped: ' . $e->getMessage());
        }
    }
}
