<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Cloudflare Turnstile check for off-site sign-in and online sign-up. Disabled when no keys are configured. */
final class Turnstile
{
    public static function siteKey(): string
    {
        return (string) App::config('turnstile.site_key', '');
    }

    public static function enabled(): bool
    {
        return self::siteKey() !== '' && (string) App::config('turnstile.secret', '') !== '';
    }

    public static function verify(string $token, string $ip): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if ($token === '') {
            return false;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => App::config('turnstile.secret'), 'response' => $token, 'remoteip' => $ip]),
            'timeout' => 8,
        ]]);
        $res = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
        $data = $res ? json_decode($res, true) : null;
        return is_array($data) && !empty($data['success']);
    }
}
