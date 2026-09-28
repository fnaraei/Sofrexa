<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Staff authentication. On devices in the restaurant staff pick their name and type a 4-digit PIN;
 * managers with "remote login" enabled can also sign in from outside with e-mail + password.
 * Permissions come from the role, adjusted by per-user allow/deny lists.
 */
final class Auth
{
    public const PIN_TRIES = 3;
    public const PIN_LOCK_MS = 30_000;

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $dir = App::storage('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        session_save_path($dir);
        $days = (int) App::config('session_days', 14);
        ini_set('session.gc_maxlifetime', (string) ($days * 86400));
        session_name((string) App::config('session_name', 'sofrexa'));
        session_set_cookie_params([
            'lifetime' => $days * 86400,
            'path' => '/',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $uid = $_SESSION['uid'] ?? null;
            if ($uid) {
                $u = Db::row('SELECT u.*, r.code AS role_code, r.name AS role_name, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.active = 1 AND u.deleted = 0', [$uid]);
                if ($u && ($u['password_hash'] ?? '') === ($_SESSION['pwv'] ?? $u['password_hash'])) {
                    self::$user = self::withPerms($u);
                } else {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    /** For CLI jobs and tests. */
    public static function actAs(?array $user): void
    {
        self::$user = $user ? self::withPerms($user) : null;
        self::$loaded = true;
    }

    private static function withPerms(array $u): array
    {
        $perms = [...json_arr($u['role_perms'] ?? '[]'), ...json_arr($u['perms_allow'] ?? '[]')];
        $u['perms'] = array_values(array_diff(Perms::expand(array_unique($perms)), json_arr($u['perms_deny'] ?? '[]')));
        return $u;
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        return in_array('*', $u['perms'], true) || in_array($perm, $u['perms'], true);
    }

    public static function require(string $perm): void
    {
        if (!self::can($perm)) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
    }

    /** @return array{ok:bool, error?:string, wait?:int} */
    public static function loginPin(string $userId, string $pin, string $ip): array
    {
        $u = Db::row('SELECT * FROM users WHERE id = ? AND active = 1 AND deleted = 0', [$userId]);
        if (!$u || !$u['pin_hash']) {
            return ['ok' => false, 'error' => 'login.unknown_user'];
        }
        if (!self::pinAllowedFrom($ip)) {
            return ['ok' => false, 'error' => 'login.pin_outside'];
        }
        $now = Clock::ms();
        if ($u['locked_until'] > $now) {
            return ['ok' => false, 'error' => 'login.locked', 'wait' => (int) ceil(($u['locked_until'] - $now) / 1000)];
        }
        if (!password_verify($pin, $u['pin_hash'])) {
            $fails = $u['failed_pins'] + 1;
            $lock = $fails >= self::PIN_TRIES ? $now + self::PIN_LOCK_MS : 0;
            Db::update('users', ['failed_pins' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = ?', [$userId]);
            Audit::log('auth.pin_failed', $u['name'] . ($lock ? ' · ' . I18n::t('audit.locked_30s', [], 'tr') : ''), 'user', $userId, ['ip' => $ip], $u);
            return $lock ? ['ok' => false, 'error' => 'login.locked', 'wait' => (int) (self::PIN_LOCK_MS / 1000)] : ['ok' => false, 'error' => 'login.wrong_pin'];
        }
        self::start($u, 'pin', $ip);
        return ['ok' => true];
    }

    public static function loginPassword(string $email, string $password, string $ip): array
    {
        $u = Db::row('SELECT * FROM users WHERE lower(email) = lower(?) AND active = 1 AND deleted = 0', [trim($email)]);
        if (!$u || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
            Audit::log('auth.password_failed', trim($email), 'user', $u['id'] ?? null, ['ip' => $ip]);
            return ['ok' => false, 'error' => 'login.wrong_password'];
        }
        if (!$u['remote_login'] && !self::pinAllowedFrom($ip)) {
            return ['ok' => false, 'error' => 'login.no_remote'];
        }
        self::start($u, 'password', $ip);
        return ['ok' => true];
    }

    private static function start(array $u, string $kind, string $ip): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['pwv'] = $u['password_hash'];
        $_SESSION['kind'] = $kind;
        Db::update('users', ['failed_pins' => 0, 'locked_until' => 0, 'last_login_at' => Clock::ms()], 'id = ?', [$u['id']]);
        self::$loaded = false;
        self::user();
        Audit::log('auth.login', $u['name'] . ' · ' . ($kind === 'pin' ? 'PIN' : I18n::t('audit.password', [], 'tr')), 'user', $u['id'], ['ip' => $ip, 'kind' => $kind]);
    }

    public static function logout(): void
    {
        if ($u = self::user()) {
            Audit::log('auth.logout', $u['name'], 'user', $u['id']);
        }
        unset($_SESSION['uid'], $_SESSION['pwv'], $_SESSION['kind']);
        session_regenerate_id(true);
        self::$user = null;
    }

    /** PIN login is limited to the restaurant network when pin_networks is configured. */
    public static function pinAllowedFrom(string $ip): bool
    {
        $nets = [...(array) App::config('pin_networks', []), ...(array) Settings::get('security.pin_networks', [])];
        if (!$nets || \Sofrexa\Sync\Emergency::allows($ip)) {
            return true;
        }
        return Net::inAny($ip, $nets);
    }

    public static function hashPin(string $pin): string
    {
        return password_hash($pin, PASSWORD_DEFAULT);
    }
}
