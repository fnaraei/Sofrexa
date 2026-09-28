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

    /** Parse "1.234,50", "1234.5", "₺1.234" → kuruş. */
    public static function parse(string|int|float|null $input): int
    {
        if (is_int($input)) {
            return $input * 100;
        }
        if (is_float($input)) {
            return (int) round($input * 100);
        }
        $s = strtr(trim((string) $input), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => ',', '٬' => '.']);
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
        if ($s === '' || $s === '-') {
            return 0;
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);   // Turkish: dot = thousands, comma = decimals
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s)) {
            $s = str_replace('.', '', $s);   // "1.234" = one thousand two hundred thirty four
        }
        return (int) round((float) $s * 100);
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
