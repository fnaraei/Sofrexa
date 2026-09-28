<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Permission catalogue (grouped as on the roles matrix, ST5) and the default roles. */
final class Perms
{
    public const GROUPS = [
        'orders' => ['orders.take', 'orders.qr_approve', 'orders.void', 'orders.discount'],
        'cash' => ['cash.pay', 'cash.moves', 'cash.nosale', 'cash.shift'],
        'kitchen_stock' => ['kitchen.ready', 'stock.manage'],
        'admin' => ['menu.manage', 'reports.view', 'finance.manage', 'staff.manage', 'backup.manage'],
    ];

    /** Kept out of the matrix (ST5): only the manager role (*) has it. */
    public const MANAGER_ONLY = ['settings.manage'];

    /** Permissions implied by others (kept out of the matrix to keep it short). */
    public const IMPLIED = [
        'orders.take' => ['orders.transfer', 'bill.print'],
        'cash.pay' => ['bill.print', 'customers.view', 'delivery.manage', 'cash.rates'],
        'staff.manage' => ['audit.view', 'users.manage'],
        'menu.manage' => ['tables.manage'],
        'reports.view' => ['customers.view'],
        'settings.manage' => ['customers.manage'],
        'kitchen.ready' => ['kitchen.view'],
    ];

    public const ROLES = [
        'waiter' => ['orders.take', 'orders.qr_approve'],
        'cashier' => ['orders.take', 'orders.qr_approve', 'orders.void', 'orders.discount', 'cash.pay', 'cash.moves', 'cash.nosale', 'cash.shift'],
        'chef' => ['kitchen.ready', 'stock.manage'],
        'stock' => ['stock.manage'],
        'courier' => [],
        'manager' => ['*'],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** Matrix permissions of a role, dropping anything unknown (e.g. from an older version). */
    public static function clean(array $perms): array
    {
        return in_array('*', $perms, true) ? ['*'] : array_values(array_intersect(self::all(), $perms));
    }

    /**
     * Whether the signed-in user may hand out $perms — on the roles matrix, as a user's switches, or as the role someone
     * is given. A manager (*) gives anything; anyone else only what they hold themselves, and never "*": a delegated
     * staff manager cannot make anyone, themselves included, more than they are (audit 8, S01).
     */
    public static function mayGrant(array $perms): bool
    {
        $u = Auth::user();
        if (!$u) {
            return false;
        }
        if (in_array('*', $u['perms'], true)) {
            return true;
        }
        return !in_array('*', $perms, true) && !array_diff(self::expand($perms), $u['perms']);
    }

    /**
     * Whether the signed-in user may manage the account $u (its details, e-mail, PIN, password link, role, switches):
     * only one who could have given it everything it has. A manager's account is a manager's to manage.
     */
    public static function mayManage(array $u): bool
    {
        $role = json_arr((string) ($u['role_perms'] ?? Db::value('SELECT perms FROM roles WHERE id = ?', [$u['role_id'] ?? ''])));
        $has = in_array('*', $role, true) ? ['*'] : array_diff(self::expand(array_unique([...$role, ...json_arr((string) ($u['perms_allow'] ?? '[]'))])), json_arr((string) ($u['perms_deny'] ?? '[]')));
        return self::mayGrant(array_values($has));
    }

    /** @throws HttpError 403 when the signed-in user may not manage the account $u */
    public static function requireManage(array $u): void
    {
        if (!self::mayManage($u)) {
            throw new HttpError(403, I18n::t('users.err_above'));
        }
    }

    /** Expand a role's matrix permissions with the implied ones. */
    public static function expand(array $perms): array
    {
        $out = $perms;
        foreach ($perms as $p) {
            foreach (self::IMPLIED[$p] ?? [] as $extra) {
                $out[] = $extra;
            }
        }
        return array_values(array_unique($out));
    }
}
