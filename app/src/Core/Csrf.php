<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Per-session CSRF token, sent as the _csrf form field or the X-CSRF header. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(20));
        }
        return $_SESSION['csrf'];
    }

    public static function verify(Request $req): void
    {
        $sent = $req->header('X-CSRF') ?: (string) ($req->input('_csrf') ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw new HttpError(419, I18n::t('err.session_expired'));
        }
    }
}
