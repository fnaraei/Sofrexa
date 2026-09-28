<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Money is kept as integer kuruş (TRY cents). Foreign cash is converted at the entered rate. */
final class Money
{
    public const CURRENCIES = ['TRY' => '₺', 'GBP' => '£', 'USD' => '$', 'EUR' => '€'];

    public static function fmt(int $kurus, bool $plus = false, ?string $lang = null): string
    {
        $neg = $kurus < 0;
        $abs = abs($kurus);
        $dec = $abs % 100 === 0 ? 0 : 2;
        $body = '₺' . I18n::num($abs / 100, $dec, $lang);
        if ($neg) {
            return '−' . $body;
        }
        return ($plus && $kurus > 0 ? '+' : '') . $body;
    }

    /**
     * Parse "1.234,50", "1234.5", "₺1.234", "₺8.000 +", "۳۶٫۸۲" → kuruş, leniently (admin forms): anything that is not a digit
     * or a separator is dropped first; 0 when no number is left. The till's payment uses the strict number() instead.
     */
    public static function parse(string|int|float|null $input): int
    {
        if (is_int($input)) {
            return $input * 100;
        }
        if (is_float($input)) {
            return (int) round($input * 100);
        }
        $s = (string) preg_replace('/[^\d,.\-−٫٬۰-۹٠-٩]/u', '', (string) $input);
        return (int) round((self::number($s) ?? 0.0) * 100);
    }

    /**
     * A typed amount as a number, read the same way as pay.js does: Persian and Arabic digits, the Persian decimal (٫)
     * and thousands (٬) signs; a comma is the decimal sign (Turkish) and dots then group thousands; without a comma a dot
     * is the decimal sign unless it groups thousands ("1.234", "1.234.567"). Null for anything else ("1,2,3", "12.5.3").
     */
    public static function number(string $input): ?float
    {
        $s = strtr(trim($input), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => ',', '٬' => '', '−' => '-']);
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}₺£$€]|TL|TRY|GBP|USD|EUR/u', '', $s);
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $s) || preg_match('/^-?\d+(,\d+)?$/', $s)) {
            return (float) str_replace(['.', ','], ['', '.'], $s);      // 1.234,50 · 1234,5 · 1.234
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return (float) $s;                                          // 36.82 · 1234.5
        }
        return null;
    }

    /** Convert a foreign amount to kuruş at a rate (TRY per unit). */
    public static function toTry(float $amountFx, float $rate): int
    {
        return (int) round($amountFx * $rate * 100);
    }

    public static function symbol(string $currency): string
    {
        return self::CURRENCIES[$currency] ?? $currency;
    }

    /** VAT included in a gross amount. */
    public static function vatOf(int $gross, float $ratePct): int
    {
        return $ratePct <= 0 ? 0 : (int) round($gross - $gross / (1 + $ratePct / 100));
    }
}
