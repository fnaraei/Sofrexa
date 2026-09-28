<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Time helpers. All timestamps are stored as unix milliseconds (UTC); business days use the
 * restaurant's local time zone and roll over at a configurable hour (default 05:00), so a
 * shift that runs past midnight still belongs to the evening it started.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function ms(): int
    {
        return self::$frozen ?? (int) floor(microtime(true) * 1000);
    }

    /** Freeze the clock (tests). */
    public static function freeze(?int $ms): void
    {
        self::$frozen = $ms;
    }

    public static function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . intdiv(self::ms(), 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** Business day (Y-m-d) of a timestamp, honouring the day rollover hour. */
    public static function day(?int $ms = null, int $rollover = 5): string
    {
        $t = (new \DateTimeImmutable('@' . intdiv($ms ?? self::ms(), 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if ((int) $t->format('G') < $rollover) {
            $t = $t->modify('-1 day');
        }
        return $t->format('Y-m-d');
    }

    /** Millisecond range [start, end) of a business day. */
    public static function dayRange(string $day, int $rollover = 5): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $start = new \DateTimeImmutable($day . sprintf(' %02d:00:00', $rollover), $tz);
        return [$start->getTimestamp() * 1000, $start->modify('+1 day')->getTimestamp() * 1000];
    }

    /**
     * The first and last business day ('Y-m-d') of a range [from, to): documents that carry a date (expenses, purchase
     * invoices) belong to a report by these days. The 27th runs until 05:00 on the 28th, but an invoice dated the 28th is not the 27th's.
     */
    public static function days(int $from, int $to, int $rollover = 5): array
    {
        return [self::day($from, $rollover), self::day(max($from, $to - 1), $rollover)];
    }

    public static function fmt(?int $ms, string $format = 'd.m.Y H:i'): string
    {
        if (!$ms) {
            return '';
        }
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
    }
}
