<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, I18n, Mailer, Settings, ValidationError};

/**
 * Staff accounts (ST8/ST9). Deleting deactivates: orders, payments and the activity log keep
 * pointing at the user. PINs are stored hashed and a new one is shown to the manager only once.
 */
final class Users
{
    public const PASSWORD_LINK_HOURS = 24;

    /** Users with role, sign-in kind and status (active | locked | passive). $filter: all | active | passive. */
    public static function list(string $filter = 'all'): array
    {
        $where = match ($filter) {
            'active' => 'AND u.active = 1',
            'passive' => 'AND u.active = 0',
            default => '',
        };
        $rows = Db::rows("SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.deleted = 0 $where ORDER BY u.active DESC, r.sort DESC, u.sort, u.name");
        $now = Clock::ms();
        foreach ($rows as &$r) {
            $r['status'] = !$r['active'] ? 'passive' : ($r['locked_until'] > $now ? 'locked' : 'active');
            $r['role_label'] = self::roleLabel($r['role_code'], $r['role_name']);
            $r['remote'] = $r['remote_login'] && $r['email'];
        }
        return $rows;
    }

    public static function counts(): array
    {
        $r = Db::row('SELECT COUNT(*) AS all_, SUM(active) AS active FROM users WHERE deleted = 0');
        return ['all' => (int) $r['all_'], 'active' => (int) $r['active'], 'passive' => (int) $r['all_'] - (int) $r['active']];
    }

    public static function roles(): array
    {
        return array_map(static fn(array $r): array => $r + ['label' => self::roleLabel($r['code'], $r['name'])],
            Db::rows('SELECT id, code, name FROM roles WHERE deleted = 0 ORDER BY sort'));
    }

    public static function roleLabel(string $code, string $name): string
    {
        return I18n::has('role.' . $code) && in_array($code, ['waiter', 'cashier', 'chef', 'stock', 'courier', 'manager'], true) ? I18n::t('role.' . $code) : $name;
    }

    /**
     * Create or update. Returns ['id' => …, 'pin' => new PIN or null].
     * @throws \InvalidArgumentException with a translated message
     */
    public static function save(array $in): array
    {
        $id = (string) ($in['id'] ?? '');
        $old = $id !== '' ? Db::row('SELECT * FROM users WHERE id = ? AND deleted = 0', [$id]) : null;
        if ($id !== '' && !$old) {
            throw new \InvalidArgumentException(I18n::t('err.not_found'));
        }
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $roleId = (string) ($in['role_id'] ?? '');
        $remote = !empty($in['remote_login']);
        $active = array_key_exists('active', $in) ? !empty($in['active']) : true;
        $errors = [];
        if ($name === '') {
            $errors['name'] = I18n::t('users.err_name');
        }
        $role = Db::row('SELECT id, code FROM roles WHERE id = ? AND deleted = 0', [$roleId]);
        if (!$role) {
            $errors['role_id'] = I18n::t('users.err_role');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = I18n::t('users.err_email');
        } elseif ($email !== '' && Db::value('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ? AND deleted = 0', [$email, $id])) {
            $errors['email'] = I18n::t('users.err_email_taken');
        }
        if ($remote && $email === '') {
            $errors['email'] = I18n::t('users.err_remote_email');
        }
        if ($old && !$active) {
            self::guardDeactivate($old);
        }
        if ($old && $old['active'] && $role && $role['code'] !== 'manager') {
            $oldRole = Db::value('SELECT code FROM roles WHERE id = ?', [$old['role_id']]);
            if ($oldRole === 'manager' && self::activeManagers() <= 1) {
                $errors['role_id'] = I18n::t('users.err_last_manager');
            }
        }
        if ($errors) {
            throw new ValidationError($errors);
        }

        $row = [
            'name' => $name,
            'phone' => trim((string) ($in['phone'] ?? '')) ?: null,
            'email' => $email ?: null,
            'role_id' => $roleId,
            'remote_login' => $remote ? 1 : 0,
            'lang' => isset(I18n::LANGS[$in['lang'] ?? '']) ? $in['lang'] : ($old['lang'] ?? 'tr'),
            'active' => $active ? 1 : 0,
        ];
        $pin = null;
        if (!$old) {
            $pin = self::newPin();
            $row += ['pin_hash' => Auth::hashPin($pin), 'created_at' => Clock::ms(), 'sort' => (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM users')];
        } else {
            $row['id'] = $id;
        }
        $id = Db::save('users', $row);
        $changes = $old ? array_keys(array_diff_assoc(array_map('strval', array_intersect_key($row, $old)), array_map('strval', array_intersect_key($old, $row)))) : ['new'];
        Audit::log('user.save', $name . ($old ? ' · ' . implode(', ', $changes) : ' · ' . I18n::t('ui.new', [], 'tr')), 'user', $id, ['changes' => $changes]);
        return ['id' => $id, 'pin' => $pin];
    }

    public static function resetPin(string $id): string
    {
        $u = self::get($id);
        $pin = self::newPin();
        Db::save('users', ['id' => $id, 'pin_hash' => Auth::hashPin($pin), 'failed_pins' => 0, 'locked_until' => 0]);
        Audit::log('user.reset_pin', $u['name'], 'user', $id);
        return $pin;
    }

    /** E-mails a one-time link for setting the remote sign-in password. */
    public static function sendPasswordLink(string $id): string
    {
        $u = self::get($id);
        if (!$u['email']) {
            throw new \InvalidArgumentException(I18n::t('users.err_no_email'));
        }
        $token = bin2hex(random_bytes(24));
        Db::save('password_resets', [
            'user_id' => $id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => Clock::ms() + self::PASSWORD_LINK_HOURS * 3_600_000,
            'created_by' => Auth::user()['id'] ?? null,
        ]);
        $base = rtrim((string) App::config('base_url'), '/') ?: ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $link = $base . '/password/set?token=' . $token;
        $lang = $u['lang'] ?: 'tr';
        $vars = ['name' => $u['name'], 'link' => $link, 'restaurant' => Settings::get('profile.name')];
        $ok = Mailer::send($u['email'], I18n::t('pw.mail_subject', $vars, $lang), I18n::t('pw.mail_body', $vars, $lang));
        Audit::log('user.password_link', $u['name'] . ' · ' . $u['email'], 'user', $id, ['sent' => $ok]);
        if (!$ok) {
            throw new \RuntimeException(I18n::t('users.link_failed'));
        }
        return $u['email'];
    }

    public static function deactivate(string $id): void
    {
        $u = self::get($id);
        self::guardDeactivate($u);
        Db::save('users', ['id' => $id, 'active' => 0, 'remote_login' => 0]);
        Audit::log('user.delete', $u['name'], 'user', $id);
    }

    /** Valid, unused reset for a token, joined with the user. */
    public static function resetByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return Db::row('SELECT p.id AS reset_id, u.* FROM password_resets p JOIN users u ON u.id = p.user_id
            WHERE p.token_hash = ? AND p.used_at IS NULL AND p.deleted = 0 AND p.expires_at > ? AND u.active = 1 AND u.deleted = 0',
            [hash('sha256', $token), Clock::ms()]);
    }

    public static function setPassword(array $reset, string $password): void
    {
        Db::tx(static function () use ($reset, $password): void {
            Db::save('users', ['id' => $reset['id'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            Db::save('password_resets', ['id' => $reset['reset_id'], 'used_at' => Clock::ms()]);
        });
        Audit::log('user.password_set', $reset['name'], 'user', $reset['id'], [], $reset);
    }

    public static function strongEnough(string $pw): bool
    {
        return mb_strlen($pw) >= 10 && preg_match('/\pL/u', $pw) && preg_match('/\d/', $pw);
    }

    private static function get(string $id): array
    {
        $u = Db::row('SELECT * FROM users WHERE id = ? AND deleted = 0', [$id]);
        if (!$u) {
            throw new \InvalidArgumentException(I18n::t('err.not_found'));
        }
        return $u;
    }

    private static function guardDeactivate(array $u): void
    {
        if (($u['id'] ?? '') === (Auth::user()['id'] ?? null)) {
            throw new \InvalidArgumentException(I18n::t('users.err_self'));
        }
        $isManager = Db::value('SELECT code FROM roles WHERE id = ?', [$u['role_id']]) === 'manager';
        if ($isManager && $u['active'] && self::activeManagers() <= 1) {
            throw new \InvalidArgumentException(I18n::t('users.err_last_manager'));
        }
    }

    private static function activeManagers(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'manager' AND u.active = 1 AND u.deleted = 0");
    }

    /** Random 4-digit PIN, avoiding the most guessable ones. */
    private static function newPin(): string
    {
        $weak = ['0000', '1111', '1234', '4321', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1212', '0123'];
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (in_array($pin, $weak, true));
        return $pin;
    }
}
