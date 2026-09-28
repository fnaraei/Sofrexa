<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Time-ordered UUIDs (version 7), so ids created on the PC and the web copy never clash and sort by time. */
final class Uuid
{
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms);                 // 8 bytes, big endian
        $time = substr($bytes, 2, 6);            // 48-bit timestamp
        $rand = random_bytes(10);
        $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70); // version 7
        $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80); // RFC 4122 variant
        $hex = bin2hex($time . $rand);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }
}
