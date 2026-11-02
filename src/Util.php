<?php

declare(strict_types=1);

/**
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Livetyping;

use Toolbox;

/**
 * Small, dependency-free helpers shared by the plugin classes.
 */
final class Util
{
    private const LOG_MAX_PER_REQUEST = 20;
    private const LOG_MAX_LENGTH      = 300;

    private static int $logged = 0;

    /**
     * Strict parse of an id coming from a request: 1 to 9 digits, > 0, nothing else
     * (no spaces, no sign, no newline, no arrays).
     */
    public static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,9}$/D', $value) !== 1) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    /**
     * Turn untrusted request input into a capped list of positive integer ids.
     *
     * @return int[]
     */
    public static function toIds(mixed $value, int $max = 1000): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = self::positiveInt($item);
            if ($id !== null) {
                $ids[$id] = $id;
            }
            if (count($ids) >= $max) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * Strict whitelist check for a single submitted value.
     *
     * @param string[] $allowed
     */
    public static function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Minimal per-session rate limit. Returns false when the same action was
     * already done less than $seconds ago.
     */
    public static function throttle(string $bucket, int $seconds): bool
    {
        $now  = time();
        $last = $_SESSION['plugin_livetyping']['throttle'][$bucket] ?? 0;

        if (is_int($last) && $last > 0 && $last <= $now && ($now - $last) < $seconds) {
            return false;
        }

        $_SESSION['plugin_livetyping']['throttle'][$bucket] = $now;
        return true;
    }

    /**
     * Fixed-window rate limit per session: at most $max calls per $window seconds, shared by
     * every action and every browser tab of the same session.
     *
     * @return int|null null = allowed, otherwise the number of seconds to wait
     */
    public static function rateLimit(string $bucket, int $max, int $window): ?int
    {
        $now   = time();
        $state = $_SESSION['plugin_livetyping']['rate'][$bucket] ?? null;
        $start = is_array($state) && is_int($state['start'] ?? null) ? $state['start'] : 0;
        $count = is_array($state) && is_int($state['count'] ?? null) ? $state['count'] : 0;

        if ($start <= 0 || $start > $now || ($now - $start) >= $window || $count < 0) {
            $start = $now;
            $count = 0;
        }
        if ($count >= $max) {
            return max(1, $window - ($now - $start));
        }

        $_SESSION['plugin_livetyping']['rate'][$bucket] = ['start' => $start, 'count' => $count + 1];
        return null;
    }

    /**
     * Single-line, control-character-free, length-limited text (for names shown to other users).
     */
    public static function cleanText(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $text);
        $text = trim($text);
        return mb_substr($text, 0, $max);
    }

    /**
     * Write to files/_log/livetyping.log. Never throws; capped and sanitised.
     */
    public static function log(string $message): void
    {
        if (self::$logged > self::LOG_MAX_PER_REQUEST) {
            return;
        }
        if (self::$logged === self::LOG_MAX_PER_REQUEST) {
            $message = 'Too many messages in this request, further messages suppressed';
        }
        self::$logged++;

        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        $message = substr($message, 0, self::LOG_MAX_LENGTH);

        try {
            Toolbox::logInFile('livetyping', $message . "\n");
        } catch (\Throwable) {
            // logging must never break the request
        }
    }

    public static function resetLogCounter(): void
    {
        self::$logged = 0;
    }
}
