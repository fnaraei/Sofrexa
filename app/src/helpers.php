<?php
/** Small global helpers used by templates and controllers. */
declare(strict_types=1);

use Sofrexa\Core\App;
use Sofrexa\Core\Auth;
use Sofrexa\Core\Csrf;
use Sofrexa\Core\I18n;
use Sofrexa\Core\Money;

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translate a key in the current language: t('tables.title', ['n' => 3]). */
function t(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

/** Pick the current language out of a JSON/array of names {tr,en,fa,ru}. */
function tn(array|string|null $names, ?string $lang = null): string
{
    return I18n::pick($names, $lang);
}

function money(?int $kurus, bool $plus = false): string
{
    return Money::fmt((int) $kurus, $plus);
}

function num(float|int|null $n, int $decimals = 0): string
{
    return I18n::num((float) $n, $decimals);
}

function url(string $path = '/', array $query = []): string
{
    $url = '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function asset(string $path): string
{
    $file = APP_DIR . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : SOFREXA_VERSION;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function icon(string $name, int $size = 24, string $class = ''): string
{
    return '<svg class="ic' . ($class !== '' ? ' ' . e($class) : '') . '" width="' . $size . '" height="' . $size . '" aria-hidden="true"><use href="' . e(asset('icons.svg')) . '#i-' . e($name) . '"/></svg>';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function user(): ?array
{
    return Auth::user();
}

function can(string $perm): bool
{
    return Auth::can($perm);
}

function config(string $key, mixed $default = null): mixed
{
    return App::config($key, $default);
}

/** Initials of a name for the avatar component ("Ayşe Yıldız" → "AY"). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

function json_arr(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

/** Normalise a phone number for lookups (digits only, local form). */
function phone_norm(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
    if (str_starts_with($d, '90') && strlen($d) === 12) {
        $d = '0' . substr($d, 2);
    }
    if (strlen($d) === 10 && $d[0] === '5') {
        $d = '0' . $d;
    }
    return $d;
}

/** "Bugün 09:01", "Dün 22:40", or "02.09.2026" for older times. */
function when_label(?int $ms): string
{
    if (!$ms) {
        return '—';
    }
    $ts = intdiv($ms, 1000);
    $time = date('H:i', $ts);
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        return t('time.today_at', ['time' => $time]);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return t('time.yesterday_at', ['time' => $time]);
    }
    $s = date('d.m.Y', $ts);
    return I18n::lang() === 'fa' ? I18n::faDigits($s) : $s;
}

/** Digits in the user's language (Persian digits for fa). */
function digits(string|int $s): string
{
    return I18n::lang() === 'fa' ? I18n::faDigits((string) $s) : (string) $s;
}

/** Time since $ms as on the Figma tiles: "12 dk", "1 sa 10 dk", "1 sa" (Persian keeps plain minutes: "۶۵ دقیقه"). */
function dur(?int $ms, ?int $now = null): string
{
    if (!$ms) {
        return '';
    }
    $m = max(0, intdiv(($now ?? \Sofrexa\Core\Clock::ms()) - $ms, 60_000));
    if ($m < 60 || I18n::lang() === 'fa') {
        return t('dur.min', ['m' => digits($m)]);
    }
    $h = intdiv($m, 60);
    $m %= 60;
    return $m === 0 ? t('dur.h', ['h' => digits($h)]) : t('dur.hm', ['h' => digits($h), 'm' => digits($m)]);
}

/** First name for compact lines ("Ayşe" from "Ayşe Yıldız"). */
function first_name(?string $name): string
{
    return $name ? explode(' ', trim($name))[0] : '';
}

/** Capitals with the Turkish dotted İ when the text is Turkish ("Bahçe" → "BAHÇE", "Izgaralar" → "IZGARALAR", "sipariş" → "SİPARİŞ"). */
function upper(string $s, ?string $lang = null): string
{
    if (($lang ?? I18n::lang()) === 'tr') {
        $s = strtr($s, ['i' => 'İ', 'ı' => 'I']);
    }
    return mb_strtoupper($s, 'UTF-8');
}
