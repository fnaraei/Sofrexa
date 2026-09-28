<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Fixed-window counters in the rate_limits table (login, online sign-up, code checks). */
final class RateLimit
{
    /** Counts a hit; returns false once $max hits happened inside the window. */
    public static function hit(string $key, int $max, int $windowMs): bool
    {
        $now = Clock::ms();
        $row = Db::row('SELECT hits, reset_at FROM rate_limits WHERE key = ?', [$key]);
        if (!$row || $row['reset_at'] <= $now) {
            Db::exec('INSERT OR REPLACE INTO rate_limits (key, hits, reset_at) VALUES (?, 1, ?)', [$key, $now + $windowMs]);
            return true;
        }
        Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE key = ?', [$key]);
        return $row['hits'] + 1 <= $max;
    }

    public static function clear(string $key): void
    {
        Db::exec('DELETE FROM rate_limits WHERE key = ?', [$key]);
    }
}
