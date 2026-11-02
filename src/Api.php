<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

use Session;
use Ticket;

/**
 * Request logic of the AJAX endpoint, kept free of HTTP details so it can be tested.
 *
 * GET  ?ticket=ID                -> who is typing now
 * POST action=ping&ticket=ID     -> "I am typing" (and who else is)
 * POST action=stop&ticket=ID     -> "I stopped typing"
 *
 * Order of checks (cheapest first, nothing is written before all of them pass):
 * method -> logged in -> rate limit -> plugin enabled for the profile -> input validation
 * -> the user can view this very ticket.
 * Every refusal is generic, it never reveals whether a ticket exists.
 */
final class Api
{
    private const NAME_MAX = 60;

    /**
     * Rate limit per session (all tabs and all actions together): at most RATE_MAX requests per
     * RATE_WINDOW seconds. A normal tab sends about one request every 3-5 s, so several open
     * tickets fit comfortably; a script hammering the endpoint does not.
     */
    private const RATE_MAX    = 10;
    private const RATE_WINDOW = 5;

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>} status, JSON payload, extra headers
     */
    public static function handle(string $method, mixed $ticket_param, mixed $action_param, ?int $now = null): array
    {
        $now ??= time();

        if ($method === 'GET') {
            $action = 'poll';
        } elseif ($method === 'POST') {
            $action = Util::choice($action_param, ['ping', 'stop']);
            if ($action === null) {
                return self::error(400, 'bad_request');
            }
        } else {
            return self::error(405, 'method_not_allowed', ['Allow' => 'GET, POST']);
        }

        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0) {
            return self::error(401, 'unauthenticated');
        }
        // Rate limit first: it only needs the session, so a flood costs the database nothing
        $retry_after = Util::rateLimit('api', self::RATE_MAX, self::RATE_WINDOW);
        if ($retry_after !== null) {
            return self::error(429, 'rate_limited', ['Retry-After' => (string) $retry_after]);
        }
        if (!Access::isUsable()) {
            return self::error(403, 'forbidden');
        }

        $tickets_id = Util::positiveInt($ticket_param);
        if ($tickets_id === null) {
            return self::error(400, 'bad_request');
        }

        // The user must be allowed to see this very ticket (entity + own-ticket rules of GLPI)
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
            return self::error(403, 'forbidden');
        }

        try {
            if ($action === 'ping') {
                State::touch($tickets_id, $users_id, $now);
                State::maybePurge($now);
            } elseif ($action === 'stop') {
                State::clear($tickets_id, $users_id);
                return [200, ['ok' => true], []];
            }

            $names = self::namesOf(State::getTypers($tickets_id, $users_id, $now));
        } catch (\Throwable $e) {
            Util::log('Request failed: ' . $e->getMessage());
            return self::error(500, 'server_error');
        }

        return [200, [
            'label' => self::buildLabel($names),
            'count' => count($names),
            'poll'  => Setting::getPollSeconds(),
        ], []];
    }

    /**
     * Display names, through GLPI's own getUserName() so its anonymisation settings
     * for the simplified interface are respected. Plain text only (the browser script
     * uses textContent), single line, length limited. Only names leave the server, never user ids.
     *
     * @param int[] $users_ids
     * @return string[]
     */
    private static function namesOf(array $users_ids): array
    {
        $names = [];
        foreach ($users_ids as $id) {
            $name    = Util::cleanText((string) getUserName($id), self::NAME_MAX);
            $names[] = $name !== '' ? $name : __('Someone', 'livetyping');
        }
        return $names;
    }

    /**
     * @param string[] $names
     */
    public static function buildLabel(array $names): string
    {
        return match (count($names)) {
            0       => '',
            1       => sprintf(__('%s is typing…', 'livetyping'), $names[0]),
            2       => sprintf(__('%1$s and %2$s are typing…', 'livetyping'), $names[0], $names[1]),
            3       => sprintf(__('%1$s, %2$s and %3$s are typing…', 'livetyping'), $names[0], $names[1], $names[2]),
            default => __('Several people are typing…', 'livetyping'),
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private static function error(int $status, string $code, array $headers = []): array
    {
        return [$status, ['error' => $code], $headers];
    }
}
