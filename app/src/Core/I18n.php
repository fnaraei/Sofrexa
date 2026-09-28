<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Four languages (tr default, en, fa right-to-left, ru). Each user picks their own language;
 * tickets and receipts are always printed in Turkish.
 */
final class I18n
{
    public const LANGS = ['tr' => 'TR', 'fa' => 'FA', 'en' => 'EN', 'ru' => 'RU'];
    private const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private static string $lang = 'tr';
    private static array $strings = [];

    public static function set(string $lang): void
    {
        self::$lang = isset(self::LANGS[$lang]) ? $lang : 'tr';
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function dir(?string $lang = null): string
    {
        return ($lang ?? self::$lang) === 'fa' ? 'rtl' : 'ltr';
    }

    /**
     * Strings live in app/lang/<module>.php, each key holding the four languages in the order
     * tr, en, fa, ru: 'login.welcome' => ['Hoş geldiniz', 'Welcome', 'خوش آمدید', 'Добро пожаловать'].
     * Keeping the languages side by side means a key can never be missing in one of them.
     */
    private static function load(string $lang): array
    {
        if (!self::$strings) {
            $order = ['tr', 'en', 'fa', 'ru'];
            self::$strings = array_fill_keys($order, []);
            foreach (glob(APP_DIR . '/lang/*.php') ?: [] as $file) {
                foreach ((array) require $file as $key => $values) {
                    foreach ($order as $i => $l) {
                        if (isset($values[$i]) && $values[$i] !== '') {
                            self::$strings[$l][$key] = $values[$i];
                        }
                    }
                }
            }
        }
        return self::$strings[$lang] ?? [];
    }

    public static function t(string $key, array $params = [], ?string $lang = null): string
    {
        $lang ??= self::$lang;
        $s = self::load($lang)[$key] ?? self::load('tr')[$key] ?? self::load('en')[$key] ?? $key;
        foreach ($params as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $lang === 'fa' ? self::faDigits($s) : $s;
    }

    public static function has(string $key): bool
    {
        return isset(self::load('tr')[$key]);
    }

    /** Pick a localized name from {tr,en,fa,ru} (JSON or array), falling back to tr then en. */
    public static function pick(array|string|null $names, ?string $lang = null): string
    {
        if (is_string($names)) {
            $decoded = json_decode($names, true);
            $names = is_array($decoded) ? $decoded : ['tr' => $names];
        }
        $names = $names ?? [];
        $lang ??= self::$lang;
        foreach ([$lang, 'tr', 'en', 'fa', 'ru'] as $l) {
            if (!empty($names[$l])) {
                return (string) $names[$l];
            }
        }
        return '';
    }

    /** Turkish number style (1.234,5); Persian digits for fa. */
    public static function num(float $n, int $decimals = 0, ?string $lang = null): string
    {
        $s = number_format($n, $decimals, ',', '.');
        return ($lang ?? self::$lang) === 'fa' ? self::faDigits(str_replace(['.', ','], ['٬', '٫'], $s)) : $s;
    }

    /** Like num(), with decimals only when there is a fraction: 5 → "5", 3.5 → "3,5". */
    public static function numAuto(float $n, int $maxDecimals = 1): string
    {
        return self::num($n, abs($n - round($n)) < 0.001 ? 0 : $maxDecimals);
    }

    /**
     * Turkish dative ("to …") after a table number or a name, with the apostrophe: "Masa 4’e", "Masa 2’ye", "Masa 9’a",
     * "Ali’ye", "Selin’e", "Mehmet’e". A number follows how it is read (dört → e, iki → ye, dokuz → a).
     */
    public static function trDative(string $s): string
    {
        $s = rtrim($s);
        $words = preg_split('/[\s·#]+/u', $s) ?: [''];
        $last = (string) end($words);
        if (preg_match('/^\d+$/', $last)) {
            $n = (int) $last;
            $units = [1 => 'e', 2 => 'ye', 3 => 'e', 4 => 'e', 5 => 'e', 6 => 'ya', 7 => 'ye', 8 => 'e', 9 => 'a'];
            $tens = [1 => 'a', 2 => 'ye', 3 => 'a', 4 => 'a', 5 => 'ye', 6 => 'a', 7 => 'e', 8 => 'e', 9 => 'a'];
            $suffix = $n === 0 ? 'a' : ($n % 10 ? $units[$n % 10] : ($n % 100 ? $tens[intdiv($n % 100, 10)] : 'e'));
            return $s . '’' . $suffix;
        }
        $low = mb_strtolower(strtr($last, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        preg_match_all('/[aıoueiöü]/u', $low, $m);
        $vowel = end($m[0]) ?: 'e';
        $suffix = in_array($vowel, ['a', 'ı', 'o', 'u'], true) ? 'a' : 'e';
        return $s . '’' . (preg_match('/[aıoueiöü]$/u', $low) ? 'y' : '') . $suffix;
    }

    /** Turkish "from" after a clock time, as it is read: "16:00’dan", "12:00’den", "13:00’ten", "18:45’ten", "19:40’tan". */
    public static function trFromTime(string $hhmm): string
    {
        $units = [1 => 'den', 2 => 'den', 3 => 'ten', 4 => 'ten', 5 => 'ten', 6 => 'dan', 7 => 'den', 8 => 'den', 9 => 'dan'];
        $tens = [1 => 'dan', 2 => 'den', 3 => 'dan', 4 => 'tan', 5 => 'den'];
        $n = substr($hhmm, 3, 2) === '00' ? (int) substr($hhmm, 0, 2) : (int) substr($hhmm, 3, 2);
        return $hhmm . '’' . ($n === 0 ? 'dan' : ($n % 10 ? $units[$n % 10] : $tens[intdiv($n, 10)] ?? 'den'));
    }

    public static function faDigits(string $s): string
    {
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** Language from ?lang, the logged-in user, the cookie, then Accept-Language. */
    public static function detect(?array $user = null): string
    {
        $q = $_GET['lang'] ?? null;
        if (is_string($q) && isset(self::LANGS[$q])) {
            setcookie('sofrexa_lang', $q, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
            return $q;
        }
        if ($user && isset(self::LANGS[$user['lang'] ?? ''])) {
            return $user['lang'];
        }
        $c = $_COOKIE['sofrexa_lang'] ?? '';
        if (isset(self::LANGS[$c])) {
            return $c;
        }
        $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (array_keys(self::LANGS) as $l) {
            if (str_starts_with($accept, $l)) {
                return $l;
            }
        }
        return 'tr';
    }

    /** All strings of a language for the JS layer. */
    public static function jsStrings(array $prefixes): array
    {
        $out = [];
        foreach (self::load('tr') as $k => $v) {
            foreach ($prefixes as $p) {
                if (str_starts_with($k, $p)) {
                    $out[$k] = self::t($k);
                }
            }
        }
        return $out;
    }

    /** Localised date: 'long' = "Pazartesi, 28 Eylül", 'short' = "28 Eylül", 'full' = "28 Eylül 2026". */
    public static function date(int $ms, string $style = 'long', ?string $lang = null): string
    {
        $ts = intdiv($ms, 1000);
        return self::t('date.' . $style, [
            'dow' => self::t('date.d' . date('w', $ts), [], $lang),
            'd' => date('j', $ts),
            'month' => self::t('date.m' . date('n', $ts), [], $lang),
            'y' => date('Y', $ts),
        ], $lang);
    }
}
