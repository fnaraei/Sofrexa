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
