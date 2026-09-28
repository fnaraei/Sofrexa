<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Clock, Db, Perms};
use Sofrexa\Modules\Staff\Staff;

/**
 * Sharing guest orders between the waiters on shift (decision 42). A QR order on a table nobody owns
 * used to alert every waiter, so whoever looked at their phone first collected all of them. It now goes
 * to one waiter: the one with the least on their hands.
 *
 * Load is the plates the kitchen has already made and nobody has carried out (READY_WEIGHT each, because
 * they are the work that cannot wait) plus the open tables. On an equal load the waiter who has gone
 * longest without an order takes it, which makes a quiet room share them in turn.
 *
 * Only people who are clocked in count. Waiters proper come first; someone else who may take orders (a
 * cashier on a quiet night) is only picked when no waiter is on shift.
 */
final class Assign
{
    /** A plate waiting to be carried out weighs this many open tables. */
    private const READY_WEIGHT = 3;

    /** What the waiter who gets a guest order must be allowed to do with it: take it, and approve a new phone's first order. */
    public const GUEST_PERMS = ['orders.take', 'orders.qr_approve'];

    /**
     * Everyone on shift who may handle a guest order, lightest load first. Somebody who may take orders but not approve
     * a QR order would be sent an alert they cannot act on, so they are left out; with nobody left, the caller alerts
     * the whole role instead.
     * @return list<array{id:string,name:string,role:string,ready:int,tables:int,load:int,since:int}>
     */
    public static function online(array $need = self::GUEST_PERMS): array
    {
        $ids = array_map('strval', array_keys(Staff::onShift()));
        if (!$ids) {
            return [];
        }
        $rows = Db::rows('SELECT u.id, u.name, r.code AS role, r.perms AS role_perms, u.perms_allow, u.perms_deny
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.active = 1 AND u.deleted = 0 AND u.id IN (' . Db::in($ids) . ')', $ids);
        $able = array_values(array_filter($rows, static fn(array $r): bool => self::may($r, $need)));
        // waiters do this job; anyone else who may take orders stands in only when no waiter is on shift
        if ($only = array_values(array_filter($able, static fn(array $r): bool => $r['role'] === 'waiter'))) {
            $able = $only;
        }
        if (!$able) {
            return [];
        }
        $mine = array_map('strval', array_column($able, 'id'));
        // plates, not lines: three kebabs on one line are still three plates to carry
        $ready = Db::pairs("SELECT o.waiter_id, CAST(ROUND(SUM(i.qty)) AS INTEGER) FROM order_items i JOIN orders o ON o.id = i.order_id
            WHERE i.status = 'ready' AND i.deleted = 0 AND o.deleted = 0 AND o.waiter_id IN (" . Db::in($mine) . ')
            GROUP BY o.waiter_id', $mine);
        // tables, not bills: a table whose bill was split is still one table to look after
        $tables = Db::pairs("SELECT waiter_id, COUNT(DISTINCT table_id) FROM orders
            WHERE status IN ('open', 'billed') AND deleted = 0 AND table_id IS NOT NULL AND waiter_id IN (" . Db::in($mine) . ')
            GROUP BY waiter_id', $mine);
        $last = Db::pairs('SELECT waiter_id, MAX(COALESCE(assigned_at, opened_at)) FROM orders
            WHERE deleted = 0 AND waiter_id IN (' . Db::in($mine) . ') GROUP BY waiter_id', $mine);
        $out = [];
        foreach ($able as $r) {
            $r['ready'] = (int) ($ready[$r['id']] ?? 0);
            $r['tables'] = (int) ($tables[$r['id']] ?? 0);
            $r['load'] = $r['ready'] * self::READY_WEIGHT + $r['tables'];
            $r['since'] = (int) ($last[$r['id']] ?? 0);
            unset($r['role_perms'], $r['perms_allow'], $r['perms_deny']);
            $out[] = $r;
        }
        usort($out, static fn(array $a, array $b): int => [$a['load'], $a['since'], $a['name']] <=> [$b['load'], $b['since'], $b['name']]);
        return $out;
    }

    /** The waiter who should take the next guest order, or null when nobody is on shift. */
    public static function pick(): ?string
    {
        return self::online()[0]['id'] ?? null;
    }

    /**
     * Gives an order to a waiter and remembers when, so the next one goes to somebody else.
     * Passing null picks the lightest-loaded waiter on shift. Returns the waiter, or null when there is none.
     */
    public static function toWaiter(string $orderId, ?string $userId = null): ?string
    {
        $userId ??= self::pick();
        if (!$userId) {
            return null;
        }
        Db::save('orders', ['id' => $orderId, 'waiter_id' => $userId, 'assigned_at' => Clock::ms()]);
        return $userId;
    }

    /** Whether a person has every permission in $need: their role's plus their own extras, minus the ones taken away (as Auth::can). */
    private static function may(array $r, array $need): bool
    {
        $perms = Perms::expand(array_values(array_unique([...json_arr((string) ($r['role_perms'] ?? '[]')), ...json_arr((string) ($r['perms_allow'] ?? '[]'))])));
        if (in_array('*', $perms, true)) {
            return true;
        }
        return !array_diff($need, array_diff($perms, json_arr((string) ($r['perms_deny'] ?? '[]'))));
    }
}
