<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Time-ordered UUIDs (version 7), so ids created on the PC and the web copy never clash and sort by time. Within one
 * process they are also strictly increasing (RFC 9562, monotonic random): two made in the same millisecond keep the
 * order they were made in, so "the newest first" is decided the same way on every copy, never by chance.
 */
final class Uuid
{
    private static int $lastMs = 0;
    private static string $lastRand = '';

    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs && self::$lastRand !== '') {
            // the same millisecond (or the clock stepped back): count on from the last id
            $ms = self::$lastMs;
            $rand = self::next(self::$lastRand);
        } else {
            $rand = random_bytes(10);
            $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70); // version 7
            $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80); // RFC 4122 variant
            $rand[3] = chr(ord($rand[3]) & 0x7f);          // headroom, so counting on within a millisecond never wraps
        }
        self::$lastMs = $ms;
        self::$lastRand = $rand;
        $bytes = pack('J', $ms);                 // 8 bytes, big endian
        $time = substr($bytes, 2, 6);            // 48-bit timestamp
        $hex = bin2hex($time . $rand);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** The random part plus one, counted in its last seven bytes (the version and variant bits are not touched). */
    private static function next(string $rand): string
    {
        for ($i = 9; $i >= 3; $i--) {
            $b = ord($rand[$i]) + 1;
            $rand[$i] = chr($b & 0xff);
            if ($b <= 0xff) {
                break;
            }
        }
        return $rand;
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }
}
