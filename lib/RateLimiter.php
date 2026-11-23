<?php
/**
 * RateLimiter
 * ===============================
 * Fixed-window counter stored in SQLite (rate_limits table).
 * Used to protect /api/login and /api/register against brute force.
 *
 * One row per counter key (e.g. "login:203.0.113.5"): the window
 * resets once window_start + window has elapsed.
 */

declare(strict_types=1);

namespace Hermes;

class RateLimiter
{
    /**
     * Registers a hit on the fixed-window counter of $key.
     *
     * @param string $key Unique counter key (e.g. "login:1.2.3.4")
     * @param int $max Max hits per window
     * @param int $windowSec Window size in seconds
     * @return array{allowed:bool, retryAfter:int} retryAfter is the number
     *         of seconds to wait when not allowed (0 otherwise)
     */
    public static function hit(string $key, int $max, int $windowSec): array
    {
        $con = Database::pdo();
        $now = time();

        $stmt = $con->prepare("SELECT window_start, hits FROM rate_limits WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row === false || ($now - (int) $row['window_start']) >= $windowSec) {
            $windowStart = $now;
            $hits = 1;
        } else {
            $windowStart = (int) $row['window_start'];
            $hits = (int) $row['hits'] + 1;
        }

        $con->prepare(
            "INSERT INTO rate_limits (key, window_start, hits) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET window_start = excluded.window_start,
                                            hits = excluded.hits"
        )->execute([$key, $windowStart, $hits]);

        // Opportunistic cleanup: the table stays tiny.
        $con->exec("DELETE FROM rate_limits WHERE window_start < " . ($now - 86400));

        return [
            'allowed' => $hits <= $max,
            'retryAfter' => $hits <= $max ? 0 : max(1, $windowStart + $windowSec - $now),
        ];
    }
}
