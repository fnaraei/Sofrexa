<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Clock, Db, Settings};

/**
 * Cancelled dishes that had reached the kitchen (IP1/IP2 "Hazır iptaller"): the ones the till still has to decide on
 * (asked the kitchen?), the cooked ones booked as waste that can go to another bill or a staff member, and what became of
 * the others. The decisions themselves are in Orders (settleVoid, reuseVoided, chargeVoidedToStaff).
 */
final class Voids
{
    public const FILTERS = ['today', 'pending', 'waste'];

    /** How many cancelled dishes wait for the till's answer (the till banner, the waste page). */
    public static function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM order_items WHERE status = 'void' AND void_stock = 'pending' AND deleted = 0");
    }

    /** Cooked cancelled dishes not given anywhere yet. */
    public static function wasteCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM order_items WHERE status = 'void' AND void_stock = 'waste' AND deleted = 0 AND void_at >= ?", [self::todayFrom()]);
    }

    private static function todayFrom(): int
    {
        $roll = (int) Settings::get('day.rollover_hour', 5);
        return Clock::dayRange(Clock::day(Clock::ms(), $roll), $roll)[0];
    }

    /**
     * The list: today's cancelled dishes of the kitchen (every state), plus the ones still waiting from earlier days.
     * $filter: today | pending | waste. Each row: id, name, qty, mods, amount, where, by, at, reason, stage (sent | ready),
     * state (pending | waste | returned | table | staff), done (text parts for the settled ones), order_id.
     */
    public static function list(string $filter = 'today'): array
    {
        $from = self::todayFrom();
        $where = "i.status = 'void' AND i.deleted = 0 AND i.void_stock IS NOT NULL AND (i.void_at >= ? OR i.void_stock = 'pending')";
        $p = [$from];
        if ($filter === 'pending' || $filter === 'waste') {
            $where .= ' AND i.void_stock = ?';
            $p[] = $filter;
        }
        $rows = Db::rows("SELECT i.*, o.channel, o.no, o.label, t.number AS table_no, a.names AS area_names, u.name AS by_name, r.name AS reuse_by_name
            FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = i.void_by LEFT JOIN users r ON r.id = i.reuse_by
            WHERE $where ORDER BY i.void_stock = 'pending' DESC, i.void_at DESC", $p);
        $out = [];
        foreach ($rows as $r) {
            $mods = array_column(json_arr($r['mods']), 'name');
            $to = null;
            if ($r['void_stock'] === 'table' && $r['reuse_ref']) {
                $t = Db::row('SELECT o.*, t.number AS table_no FROM order_items l JOIN orders o ON o.id = l.order_id LEFT JOIN tables t ON t.id = o.table_id WHERE l.id = ?', [$r['reuse_ref']]);
                $to = $t ? Orders::where($t) : null;
            } elseif ($r['void_stock'] === 'staff' && $r['reuse_ref']) {
                $to = (string) Db::value('SELECT name FROM users WHERE id = ?', [$r['reuse_ref']]);
            }
            $out[] = [
                'id' => $r['id'], 'order_id' => $r['order_id'], 'name' => $r['name'], 'qty' => (float) $r['qty'], 'mods' => $mods,
                'amount' => (int) round((float) $r['qty'] * ((int) $r['unit_price'] + (int) $r['mods_price'])),
                'where' => Orders::where($r + ['table_no' => $r['table_no']]), 'area' => $r['area_names'], 'by' => (string) $r['by_name'], 'at' => (int) $r['void_at'],
                'reason' => (string) $r['void_reason'], 'stage' => $r['ready_at'] || $r['served_at'] ? 'ready' : 'sent',
                'state' => $r['void_stock'], 'to' => $to, 'done_at' => $r['reuse_at'] ? (int) $r['reuse_at'] : null, 'done_by' => (string) $r['reuse_by_name'],
            ];
        }
        return $out;
    }

    /**
     * IP1 figures: waiting (count, amount), waste today (price, ingredient cost), given to another bill (count, amount, the
     * last bill), charged to staff (count, amount); all = today's rows plus older ones still waiting.
     */
    public static function stats(): array
    {
        $from = self::todayFrom();
        $by = [];
        foreach (Db::rows("SELECT void_stock AS s, COUNT(*) AS n, COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) AS a FROM order_items
            WHERE status = 'void' AND deleted = 0 AND void_stock IS NOT NULL AND (void_at >= ? OR void_stock = 'pending') GROUP BY void_stock", [$from]) as $r) {
            $by[$r['s']] = ['n' => (int) $r['n'], 'a' => (int) $r['a']];
        }
        $cost = (int) round(-(float) Db::value("SELECT COALESCE(SUM(m.qty * m.unit_cost), 0) FROM stock_moves m JOIN order_items i ON i.id = m.order_item_id
            WHERE m.reason LIKE 'waste%' AND i.status = 'void' AND i.void_stock = 'waste' AND i.void_at >= ?", [$from]));
        $last = Db::row("SELECT o.*, t.number AS table_no FROM order_items v JOIN order_items l ON l.id = v.reuse_ref JOIN orders o ON o.id = l.order_id LEFT JOIN tables t ON t.id = o.table_id
            WHERE v.status = 'void' AND v.void_stock = 'table' AND v.void_at >= ? ORDER BY v.reuse_at DESC LIMIT 1", [$from]);
        $g = static fn(string $k, string $f): int => $by[$k][$f] ?? 0;
        return ['pending' => $g('pending', 'n'), 'pending_amount' => $g('pending', 'a'), 'waste' => $g('waste', 'a'), 'waste_n' => $g('waste', 'n'), 'waste_cost' => $cost,
            'table' => $g('table', 'n'), 'table_amount' => $g('table', 'a'), 'table_last' => $last ? Orders::where($last) : null,
            'staff' => $g('staff', 'n'), 'staff_amount' => $g('staff', 'a'), 'all' => array_sum(array_column($by, 'n'))];
    }

    /** IP3: open bills a cooked dish can go to (not the one it was cancelled from), newest activity first. */
    public static function openBills(string $exceptOrder): array
    {
        return Db::rows("SELECT o.*, t.number AS table_no, a.names AS area_names, u.name AS waiter_name FROM orders o LEFT JOIN tables t ON t.id = o.table_id
            LEFT JOIN areas a ON a.id = t.area_id LEFT JOIN users u ON u.id = o.waiter_id
            WHERE o.status IN ('open', 'billed') AND o.deleted = 0 AND o.id <> ?
              AND EXISTS (SELECT 1 FROM order_items l WHERE l.order_id = o.id AND l.deleted = 0 AND l.status <> 'void')
            ORDER BY o.channel NOT IN ('table', 'qr'), t.number + 0, o.opened_at", [$exceptOrder]);
    }

    /** IP4: staff to charge a dish to — people clocked in first, then everyone active. */
    public static function staff(): array
    {
        $on = array_map('strval', array_keys(\Sofrexa\Modules\Staff\Staff::onShift()));
        $rows = Db::rows("SELECT u.id, u.name, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.active = 1 AND u.deleted = 0 ORDER BY u.name");
        foreach ($rows as &$r) {
            $r['on'] = in_array($r['id'], $on, true);
            $r['role_label'] = \Sofrexa\Modules\Staff\Users::roleLabel($r['role_code'], $r['role_name']);
        }
        unset($r);
        usort($rows, static fn(array $a, array $b): int => [$b['on'], $a['name']] <=> [$a['on'], $b['name']]);
        return $rows;
    }
}
