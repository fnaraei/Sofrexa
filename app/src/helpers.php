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
