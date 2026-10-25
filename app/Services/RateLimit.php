<?php
/**
 * Fixed-window rate limiting backed by the `rate_limits` table.
 *
 *   RateLimit::hit('otp:' . $email, 5, 3600)   // true when allowed
 *   RateLimit::guard('ip:' . $ip, 120, 60)      // emits 429 when exceeded
 */

declare(strict_types=1);

namespace App\Services;

use Throwable;

class RateLimit
{
    /**
     * Count one hit for $key; returns true when still within $limit per $windowSeconds.
     * Fails open (returns true) if the DB is unavailable, so a DB blip never locks users out.
     */
    public static function hit(string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true;
        }
        try {
            $pdo = db_try();
            if (!$pdo) {
                return true;
            }
            $key = substr($key, 0, 191);
            $window = (int) floor(time() / $windowSeconds);

            $st = $pdo->prepare('SELECT count FROM rate_limits WHERE rl_key = ? AND window_start = ?');
            $st->execute([$key, $window]);
            $row = $st->fetch();
            if ($row) {
                $count = (int) $row['count'] + 1;
                $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE rl_key = ? AND window_start = ?')->execute([$key, $window]);
            } else {
                $count = 1;
                try {
                    $pdo->prepare('INSERT INTO rate_limits (rl_key, window_start, count) VALUES (?, ?, 1)')->execute([$key, $window]);
                } catch (Throwable $race) {
                    // concurrent insert — count it as a hit anyway
                    $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE rl_key = ? AND window_start = ?')->execute([$key, $window]);
                    $count = 2;
                }
            }

            // Opportunistic cleanup of stale windows (about 1 request in 200).
            if (random_int(1, 200) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$window - 48]);
            }
            return $count <= $limit;
        } catch (Throwable $e) {
            log_msg('error', 'RateLimit: ' . $e->getMessage());
            return true;
        }
    }

    /** hit() + 429 response when exceeded. */
    public static function guard(string $key, int $limit, int $windowSeconds, string $message = 'Too many requests'): void
    {
        if (!self::hit($key, $limit, $windowSeconds)) {
            header('Retry-After: ' . $windowSeconds);
            fail('rate_limited', $message, 429);
        }
    }

    /** Current count for a key in the current window (0 if none). */
    public static function count(string $key, int $windowSeconds): int
    {
        try {
            $pdo = db_try();
            if (!$pdo) {
                return 0;
            }
            $window = (int) floor(time() / $windowSeconds);
            $st = $pdo->prepare('SELECT count FROM rate_limits WHERE rl_key = ? AND window_start = ?');
            $st->execute([substr($key, 0, 191), $window]);
            $row = $st->fetch();
            return $row ? (int) $row['count'] : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
