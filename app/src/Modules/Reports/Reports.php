<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Reports;

use Sofrexa\Core\{Clock, Db, I18n, Settings};
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\Modules\Orders\{Orders, Rates};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Modules\Stock\Stock;

/**
 * Report figures (R1–R8). Every figure is read from the ledgers for a period [from, to) in ms; a period
 * follows the business day (it starts at the rollover hour). Sales are paid bills, VAT included.
 */
final class Reports
{
    public const PERIODS = ['today', 'yesterday', '7d', 'month', 'last_month', 'quarter', 'year', 'custom'];

    // ------------------------------------------------------------ periods

    /**
     * ['key', 'from', 'to', 'prev_from', 'prev_to', 'first' (Y-m-d), 'last' (Y-m-d)].
     * The previous period has the same length and ends where this one starts (today: the same hours yesterday).
     */
    public static function period(string $key, string $fromDay = '', string $toDay = ''): array
    {
        $roll = (int) Settings::get('day.rollover_hour', 5);
        $today = Orders::businessDay();
        $start = static fn(string $d): int => Clock::dayRange($d, $roll)[0];
        $now = Clock::ms();
        $key = in_array($key, self::PERIODS, true) ? $key : 'today';
        $shift = static fn(string $d, string $mod): string => date('Y-m-d', (int) strtotime($d . ' ' . $mod));
        switch ($key) {
            case 'yesterday':
                $y = $shift($today, '-1 day');
                [$from, $to] = [$start($y), $start($today)];
                break;
            case '7d':
                [$from, $to] = [$start($shift($today, '-6 days')), $now + 1];
                break;
            case 'month':
                [$from, $to] = [$start(substr($today, 0, 7) . '-01'), $now + 1];
                break;
            case 'last_month':
                $m0 = substr($today, 0, 7) . '-01';
                [$from, $to] = [$start($shift($m0, '-1 month')), $start($m0)];
                break;
            case 'quarter':
                $q = (int) floor(((int) substr($today, 5, 2) - 1) / 3) * 3 + 1;
                [$from, $to] = [$start(substr($today, 0, 4) . sprintf('-%02d-01', $q)), $now + 1];
                break;
            case 'year':
                [$from, $to] = [$start(substr($today, 0, 4) . '-01-01'), $now + 1];
                break;
            case 'custom':
                $a = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDay) ? $fromDay : substr($today, 0, 7) . '-01';
                $b = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDay) && $toDay >= $a ? $toDay : $today;
                [$from, $to] = [$start($a), min($start($shift($b, '+1 day')), $now + 1)];
                break;
            default:
                [$from, $to] = [$start($today), $now + 1];
        }
        $len = $to - $from;
        // compared with the same stretch of the period before: today 05:00–14:00 with yesterday 05:00–14:00, this month
        // so far with the same number of days of last month, this quarter/year so far with the same part of the last one
        $first = date('Y-m-d', intdiv($from, 1000));
        $back = match ($key) {
            'today' => '-1 day',
            'month', 'last_month' => '-1 month',
            'quarter' => '-3 months',
            'year' => '-1 year',
            default => null,
        };
        if ($back !== null) {
            $pf = $start($shift($first, $back));
            $prev = [$pf, $pf + $len];
        } else {
            $prev = [$from - $len, $from];
        }
        return ['key' => $key, 'from' => $from, 'to' => $to, 'prev_from' => $prev[0], 'prev_to' => $prev[1],
            // the last business day: an hour before the rollover still belongs to the day before
            'first' => date('Y-m-d', intdiv($from, 1000)), 'last' => date('Y-m-d', intdiv(max($from, $to - 1), 1000) - $roll * 3600)];
    }

    /** "1–28 Eylül 2026", "28 Eylül", "27 Ağu – 2 Eyl". */
    public static function rangeLabel(array $p): string
    {
        [$a, $b] = [$p['first'], $p['last']];
        $d = static fn(string $x): int => (int) substr($x, 8, 2);
        $m = static fn(string $x): string => t('date.m' . (int) substr($x, 5, 2));
        if ($a === $b) {
            return digits($d($a)) . ' ' . $m($a) . ' ' . digits(substr($a, 0, 4));
        }
        if (substr($a, 0, 7) === substr($b, 0, 7)) {
            return digits($d($a) . '–' . $d($b)) . ' ' . $m($a) . ' ' . digits(substr($a, 0, 4));
        }
        return digits($d($a)) . ' ' . $m($a) . ' – ' . digits($d($b)) . ' ' . $m($b) . ' ' . digits(substr($b, 0, 4));
    }

    // ------------------------------------------------------------ sales

    /**
     * The paid bills of a period (settled in [from, to)) or of one till shift — the one set every sales figure is read from:
     * id, day (the business day it was settled), channel, subtotal, discount, total, guests, waiter_id and its VAT split
     * (rate => [gross, vat]) exactly as the receipt shows it (Orders::vatSplit: the parts add up to the total).
     */
    public static function bills(int $from, int $to, ?string $shiftId = null): array
    {
        if ($shiftId !== null) {
            // bills settled in the shift; older ones (before closed_shift_id) by the shift's hours
            $s = Db::row('SELECT opened_at, closed_at FROM shifts WHERE id = ?', [$shiftId]);
            $where = "o.status = 'paid' AND o.deleted = 0 AND (o.closed_shift_id = ? OR (o.closed_shift_id IS NULL AND o.closed_at >= ? AND o.closed_at < ?))";
            $p = [$shiftId, (int) ($s['opened_at'] ?? 0), (int) (($s['closed_at'] ?? null) ?: PHP_INT_MAX)];
        } else {
            $where = "o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ?";
            $p = [$from, $to];
        }
        $gross = [];
        foreach (Db::rows("SELECT i.order_id, i.vat_rate, SUM(ROUND(i.qty * (i.unit_price + i.mods_price))) AS g FROM order_items i JOIN orders o ON o.id = i.order_id
            WHERE $where AND i.deleted = 0 AND i.status <> 'void' GROUP BY i.order_id, i.vat_rate", $p) as $g) {
            $gross[$g['order_id']][(string) (float) $g['vat_rate']] = (int) $g['g'];
        }
        $roll = (int) Settings::get('day.rollover_hour', 5);
        $out = [];
        foreach (Db::rows("SELECT o.id, o.closed_at, o.channel, o.subtotal, o.discount, o.total, o.guests, o.waiter_id FROM orders o WHERE $where ORDER BY o.closed_at", $p) as $o) {
            $out[] = ['id' => $o['id'], 'day' => Clock::day((int) $o['closed_at'], $roll), 'channel' => $o['channel'], 'subtotal' => (int) $o['subtotal'],
                'discount' => (int) $o['discount'], 'total' => (int) $o['total'], 'guests' => (int) $o['guests'], 'waiter_id' => $o['waiter_id'],
                'vat' => \Sofrexa\Modules\Orders\Orders::vatSplit($gross[$o['id']] ?? [], (int) $o['total'])];
        }
        return $out;
    }

    /** Sums of a set of bills: bills, sales, discount, discount_n, guests, vat (total) and by_rate (rate => [gross, vat]). */
    public static function sum(array $bills): array
    {
        $r = ['bills' => count($bills), 'sales' => 0, 'discount' => 0, 'discount_n' => 0, 'guests' => 0, 'vat' => 0, 'by_rate' => []];
        foreach ($bills as $b) {
            $r['sales'] += $b['total'];
            $r['discount'] += $b['discount'];
            $r['discount_n'] += $b['discount'] > 0 ? 1 : 0;
            $r['guests'] += in_array($b['channel'], ['table', 'qr'], true) ? max($b['guests'], 1) : 0;
            foreach ($b['vat'] as $rate => [$g, $v]) {
                $r['by_rate'][$rate] ??= [0, 0];
                $r['by_rate'][$rate][0] += $g;
                $r['by_rate'][$rate][1] += $v;
                $r['vat'] += $v;
            }
        }
        ksort($r['by_rate'], SORT_NUMERIC);
        return $r;
    }

    /** Sales, bills, average bill, guests, discounts, voids, VAT, food cost of a period. */
    public static function kpis(int $from, int $to): array
    {
        $r = self::sum(self::bills($from, $to));
        $v = self::voidTotals($from, $to);
        $sales = $r['sales'];
        $vat = $r['vat'];
        $cost = self::costOfSales($from, $to);
        $net = $sales - $vat;
        // the average bill in whole lira, as on the design
        return ['sales' => $sales, 'bills' => $r['bills'], 'avg' => $r['bills'] > 0 ? (int) round($sales / $r['bills'] / 100) * 100 : 0,
            'guests' => $r['guests'], 'discount' => $r['discount'], 'discount_n' => $r['discount_n'], 'voids' => $v['amount'], 'voids_n' => $v['n'],
            'vat' => $vat, 'net' => $net, 'cost' => $cost, 'food_cost' => $net > 0 ? $cost * 100 / $net : null];
    }

    /** % change, or null without a base. */
    public static function change(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? ($now - $before) * 100 / $before : null;
    }

    /** VAT inside the paid bills, as their receipts show it. */
    public static function vat(int $from, int $to): int
    {
        return self::sum(self::bills($from, $to))['vat'];
    }

    /** VAT split by rate: rate => [gross, vat]. */
    public static function vatByRate(int $from, int $to): array
    {
        return self::sum(self::bills($from, $to))['by_rate'];
    }

    /**
     * Cost of what was sold (recipes), kuruş, VAT excluded: the ingredients of the bills settled in the period, whenever they
     * went to the kitchen — so the food cost of a bill opened last night and paid today sits next to its sales (a give-back
     * or a void booked as waste leaves the sale).
     */
    public static function costOfSales(int $from, int $to): int
    {
        return (int) round(-(float) Db::value("SELECT COALESCE(SUM(m.qty * m.unit_cost), 0) FROM stock_moves m JOIN order_items i ON i.id = m.order_item_id JOIN orders o ON o.id = i.order_id
            WHERE m.reason IN ('sale', 'void') AND o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ?", [$from, $to]));
    }

    /** Ingredients that left the shelf for sales in the period by the time they went to the kitchen (the stock report). */
    public static function consumption(int $from, int $to): int
    {
        return (int) round(-(float) Db::value("SELECT COALESCE(SUM(qty * unit_cost), 0) FROM stock_moves WHERE reason IN ('sale', 'void') AND at >= ? AND at < ?", [$from, $to]));
    }

    /** Waste booked in the period (kuruş). */
    public static function waste(int $from, int $to): int
    {
        return (int) round(-(float) Db::value("SELECT COALESCE(SUM(qty * unit_cost), 0) FROM stock_moves WHERE reason LIKE 'waste%' AND at >= ? AND at < ?", [$from, $to]));
    }

    /** Sales per hour of the day: hour => kuruş, from 12 (or the first hour with sales) to 23. */
    public static function hourly(int $from, int $to): array
    {
        $h = [];
        foreach (Db::rows("SELECT closed_at, total FROM orders WHERE status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ?", [$from, $to]) as $r) {
            $k = (int) date('G', intdiv((int) $r['closed_at'], 1000));
            // after midnight counts with the evening (the business day)
            $k = $k < (int) Settings::get('day.rollover_hour', 5) ? $k + 24 : $k;
            $h[$k] = ($h[$k] ?? 0) + (int) $r['total'];
        }
        $lo = min(12, $h ? min(array_keys($h)) : 12);
        $hi = max(23, $h ? max(array_keys($h)) : 23);
        $out = [];
        for ($i = $lo; $i <= $hi; $i++) {
            $out[$i % 24] = $h[$i] ?? 0;
        }
        return $out;
    }

    /** Sales per channel: table (with qr inside), qr, takeaway, delivery, online. */
    public static function channels(int $from, int $to): array
    {
        $by = Db::pairs("SELECT channel, SUM(total) FROM orders WHERE status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ? GROUP BY channel", [$from, $to]);
        $by = array_map('intval', $by);
        return ['table' => ($by['table'] ?? 0) + ($by['qr'] ?? 0), 'qr' => $by['qr'] ?? 0, 'takeaway' => $by['takeaway'] ?? 0, 'delivery' => $by['delivery'] ?? 0, 'online' => $by['online'] ?? 0];
    }

    /**
     * Money taken for bills in the period, per method and currency:
     * cash_try, fx [cur => [fx, try]], card, account, discount.
     */
    public static function payments(int $from, int $to): array
    {
        $out = ['cash_try' => 0, 'fx' => [], 'card' => 0, 'account' => 0];
        foreach (Db::rows("SELECT p.method, p.currency, SUM(p.amount) AS try, SUM(p.amount_fx) AS fx FROM payments p JOIN orders o ON o.id = p.order_id
            WHERE p.at >= ? AND p.at < ? AND o.deleted = 0 GROUP BY p.method, p.currency", [$from, $to]) as $r) {
            if ($r['method'] === 'cash' && $r['currency'] !== 'TRY') {
                $out['fx'][$r['currency']] = ['fx' => (float) $r['fx'], 'try' => (int) $r['try']];
            } elseif ($r['method'] === 'cash') {
                $out['cash_try'] += (int) $r['try'];
            } elseif (isset($out[$r['method']])) {
                $out[$r['method']] += (int) $r['try'];
            }
        }
        return $out;
    }

    /** Best sellers: item, name, photo, qty, amount. */
    public static function topItems(int $from, int $to, int $limit = 5): array
    {
        $rows = Db::rows("SELECT i.item_id, MAX(i.name) AS name, SUM(i.qty) AS qty, SUM(ROUND(i.qty * (i.unit_price + i.mods_price))) AS amount, MAX(m.image) AS image
            FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN items m ON m.id = i.item_id
            WHERE o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ? AND i.deleted = 0 AND i.status <> 'void'
            GROUP BY i.item_id ORDER BY amount DESC LIMIT $limit", [$from, $to]);
        foreach ($rows as &$r) {
            $r['photo'] = $r['image'] ? Menu::photoUrl($r['image'], 120) : null;
        }
        unset($r);
        return $rows;
    }

    /** Sales by item and by category for the sales report. */
    public static function byItem(int $from, int $to): array
    {
        return Db::rows("SELECT i.item_id, MAX(i.name) AS name, c.names AS cat, SUM(i.qty) AS qty, SUM(ROUND(i.qty * (i.unit_price + i.mods_price))) AS amount
            FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN items m ON m.id = i.item_id LEFT JOIN categories c ON c.id = m.category_id
            WHERE o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ? AND i.deleted = 0 AND i.status <> 'void'
            GROUP BY i.item_id ORDER BY amount DESC", [$from, $to]);
    }

    /** Open bills now: count and amount (tables). */
    public static function openTables(): array
    {
        $r = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM orders WHERE channel IN ('table', 'qr') AND status IN ('open', 'billed', 'pending') AND deleted = 0");
        return ['n' => (int) $r['n'], 'amount' => (int) $r['s']];
    }

    // ------------------------------------------------------------ voids (R6)

    public static function voidTotals(int $from, int $to): array
    {
        $r = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) AS a FROM order_items WHERE status = 'void' AND sent_at IS NOT NULL AND void_at >= ? AND void_at < ?", [$from, $to]);
        return ['n' => (int) $r['n'], 'amount' => (int) $r['a']];
    }

    /**
     * Items removed after they were sent, with the stage they had reached, who removed them, who approved and why.
     * $f: who (user id), after_kitchen (bool).
     */
    public static function voids(int $from, int $to, array $f = []): array
    {
        $where = "i.status = 'void' AND i.sent_at IS NOT NULL AND i.void_at >= ? AND i.void_at < ?";
        $p = [$from, $to];
        if (!empty($f['who'])) {
            $where .= ' AND i.void_by = ?';
            $p[] = $f['who'];
        }
        if (!empty($f['after_kitchen'])) {
            $where .= ' AND (i.ready_at IS NOT NULL OR i.started_at IS NOT NULL)';
        }
        $rows = Db::rows("SELECT i.*, o.no, o.channel, o.waiter_id, t.number AS table_no, a.names AS area, u.name AS by_name, w.name AS waiter_name
            FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = i.void_by LEFT JOIN users w ON w.id = o.waiter_id
            WHERE $where ORDER BY i.void_at DESC", $p);
        foreach ($rows as &$r) {
            $r['amount'] = (int) round((float) $r['qty'] * ((int) $r['unit_price'] + (int) $r['mods_price']));
            $r['stage'] = $r['served_at'] ? 'served' : ($r['ready_at'] ? 'ready' : ($r['started_at'] ? 'cooking' : 'sent'));
            // the approval: a manager or cashier voided an item a waiter had sent
            $r['approver'] = $r['void_by'] && $r['waiter_id'] && $r['void_by'] !== $r['waiter_id'] ? $r['by_name'] : null;
        }
        unset($r);
        return $rows;
    }

    // ------------------------------------------------------------ staff (R7 / R8)

    /** Per person who served bills: sales, bills, average, voids, discounts, share, hours worked. */
    public static function staff(int $from, int $to): array
    {
        $rows = Db::rows("SELECT o.waiter_id AS id, u.name, r.code AS role_code, r.name AS role_name, COUNT(*) AS bills, SUM(o.total) AS sales
            FROM orders o JOIN users u ON u.id = o.waiter_id JOIN roles r ON r.id = u.role_id
            WHERE o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ? GROUP BY o.waiter_id ORDER BY sales DESC", [$from, $to]);
        $voids = [];
        foreach (Db::rows("SELECT void_by, COUNT(*) AS n, SUM(ROUND(qty * (unit_price + mods_price))) AS a FROM order_items WHERE status = 'void' AND sent_at IS NOT NULL AND void_at >= ? AND void_at < ? GROUP BY void_by", [$from, $to]) as $v) {
            $voids[(string) $v['void_by']] = ['n' => (int) $v['n'], 'amount' => (int) $v['a']];
        }
        $disc = self::discountsGiven($from, $to, false)['by'];
        $total = array_sum(array_column($rows, 'sales'));
        foreach ($rows as &$r) {
            $r['sales'] = (int) $r['sales'];
            $r['bills'] = (int) $r['bills'];
            $r['avg'] = $r['bills'] ? (int) round($r['sales'] / $r['bills'] / 100) * 100 : 0;
            $r['voids'] = $voids[$r['id']] ?? ['n' => 0, 'amount' => 0];
            $r['discount'] = (int) ($disc[$r['id']] ?? 0);
            $r['share'] = $total > 0 ? $r['sales'] * 100 / $total : 0;
            $r['hours'] = (int) round(Staff::worked($r['id'], $from, $to) / 3_600_000);
            $r['role_label'] = \Sofrexa\Modules\Staff\Users::roleLabel($r['role_code'], $r['role_name']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Discounts given in the period (by when they were given) as much as they take off their bills now — a percentage
     * follows its bill, a removed one counts nothing: by (user_id => kuruş), n (bills), amount. $points: with points used.
     */
    public static function discountsGiven(int $from, int $to, bool $points = true): array
    {
        $by = [];
        $bills = [];
        foreach (Db::rows("SELECT DISTINCT d.order_id, o.subtotal, o.discount FROM order_discounts d JOIN orders o ON o.id = d.order_id AND o.deleted = 0 AND o.status <> 'void'
            WHERE d.kind <> 'reverse' AND d.at >= ? AND d.at < ?", [$from, $to]) as $o) {
            $raw = [];
            foreach (\Sofrexa\Modules\Customers\Loyalty::activeDiscounts($o['order_id']) as $e) {
                $raw[] = [$e, $e['kind'] === 'pct' ? (int) round((int) $o['subtotal'] * (float) $e['value'] / 100) : (int) $e['amount']];
            }
            $all = array_sum(array_column($raw, 1));
            $scale = $all > 0 ? min(1.0, (int) $o['discount'] / $all) : 0.0; // what the bill could really take
            foreach ($raw as [$e, $a]) {
                if ((int) $e['at'] < $from || (int) $e['at'] >= $to || (!$points && $e['reason'] === 'puan')) {
                    continue;
                }
                $v = (int) round($a * $scale);
                if ($v > 0) {
                    $by[(string) $e['user_id']] = ($by[(string) $e['user_id']] ?? 0) + $v;
                    $bills[$o['order_id']] = true;
                }
            }
        }
        return ['by' => $by, 'n' => count($bills), 'amount' => array_sum($by)];
    }

    /** Kitchen speed: average preparation (min), late share and count, slowest item. */
    public static function kitchen(int $from, int $to): array
    {
        $late = max(3, (int) Settings::get('order.late_minutes', 20));
        // CAST: PDO sends numbers as text, and a difference (no column affinity) would compare below any text
        $r = Db::row('SELECT COUNT(*) AS n, AVG(ready_at - sent_at) AS avg, SUM(CASE WHEN ready_at - sent_at > CAST(? AS INTEGER) THEN 1 ELSE 0 END) AS late
            FROM order_items WHERE ready_at IS NOT NULL AND sent_at >= ? AND sent_at < ? AND deleted = 0 AND station = ?', [$late * 60_000, $from, $to, 'kitchen']);
        $slow = Db::row("SELECT MAX(name) AS name, AVG(ready_at - sent_at) AS avg FROM order_items WHERE ready_at IS NOT NULL AND sent_at >= ? AND sent_at < ? AND deleted = 0 AND station = 'kitchen'
            GROUP BY item_id HAVING COUNT(*) >= 3 ORDER BY avg DESC LIMIT 1", [$from, $to]);
        $late_n = (int) $r['late'];
        $orders = (int) Db::value("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE ready_at IS NOT NULL AND sent_at >= ? AND sent_at < ? AND deleted = 0 AND station = 'kitchen' AND ready_at - sent_at > CAST(? AS INTEGER)", [$from, $to, $late * 60_000]);
        return ['avg' => $r['avg'] ? (int) round((float) $r['avg'] / 60_000) : null, 'late_pct' => (int) $r['n'] > 0 ? $late_n * 100 / (int) $r['n'] : null,
            'late_orders' => $orders, 'late_min' => $late, 'target' => (int) Settings::get('kds.late_minutes', 15),
            'slowest' => $slow ? ['name' => $slow['name'], 'min' => (int) round((float) $slow['avg'] / 60_000)] : null];
    }

    // ------------------------------------------------------------ end of day (R3)

    /** Closed shifts, newest first (the Z reports). */
    public static function shifts(int $limit = 30): array
    {
        return Db::rows("SELECT s.id, s.z_no, s.opened_at, s.closed_at, u.name FROM shifts s LEFT JOIN users u ON u.id = s.user_id
            WHERE s.deleted = 0 ORDER BY s.opened_at DESC LIMIT $limit");
    }

    /** Everything of the R3 report for one shift (Z). */
    public static function z(string $shiftId): array
    {
        $s = Db::row('SELECT s.*, u.name FROM shifts s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$shiftId]) ?? throw new \Sofrexa\Core\HttpError(404);
        $sum = \Sofrexa\Modules\Orders\Shifts::summary($shiftId);
        // sales: the bills settled in this shift (a bill paid half here and half in the next one is a sale of the next one);
        // money: every payment taken in this shift, whichever bill it was for
        $bills = self::bills(0, 0, $shiftId);
        $o = self::sum($bills);
        $vat = [];
        foreach ($o['by_rate'] as $rate => [, $v]) {
            if ((float) $rate > 0) {
                $vat[$rate] = $v;
            }
        }
        $ch = [];
        foreach ($bills as $b) {
            $ch[$b['channel']] = ($ch[$b['channel']] ?? 0) + $b['total'];
        }
        $fx = Db::rows("SELECT currency, SUM(amount_fx) AS fx, SUM(amount) AS try, AVG(rate) AS rate FROM payments WHERE shift_id = ? AND method = 'cash' AND currency <> 'TRY' AND order_id IS NOT NULL GROUP BY currency", [$shiftId]);
        $cashTry = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND method = 'cash' AND currency = 'TRY' AND order_id IS NOT NULL", [$shiftId]);
        $methods = array_map('intval', Db::pairs("SELECT method, SUM(amount) FROM payments WHERE shift_id = ? AND order_id IS NOT NULL GROUP BY method", [$shiftId]));
        $courier = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND courier_id IS NOT NULL AND method = 'cash'", [$shiftId]);
        $end = (int) ($s['closed_at'] ?: Clock::ms());
        $open = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS a FROM orders WHERE channel IN ('table', 'qr') AND deleted = 0 AND opened_at < ? AND status <> 'void'
            AND (closed_at IS NULL OR closed_at > ?)", [$end, $end]);
        $counted = json_arr($s['counted']);
        $expected = $s['expected'] !== null ? json_arr($s['expected']) : $sum['cash'];
        $collect = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND order_id IS NULL", [$shiftId]);
        return ['s' => $s, 'sum' => $sum, 'bills' => $o['bills'], 'sales' => $o['sales'], 'discount' => $o['discount'], 'discount_n' => $o['discount_n'],
            'guests' => $o['guests'], 'vat' => $vat, 'channels' => $ch, 'fx' => $fx, 'cash_try' => $cashTry, 'methods' => $methods, 'courier' => $courier,
            'open' => ['n' => (int) $open['n'], 'amount' => (int) $open['a']], 'counted' => $counted, 'expected' => $expected, 'collections' => $collect,
            'note' => (string) (json_arr($s['note'])['close'] ?? ''), 'closed' => (bool) $s['closed_at']];
    }

    // ------------------------------------------------------------ attention (R1 / R2)

    /** What needs a look now: critical stock, voids and discounts today, stale rates, the web copy. Each: [icon, tone, text]. */
    public static function attention(int $from, int $to): array
    {
        $out = [];
        $crit = array_values(array_filter(Stock::items(), static fn(array $r): bool => $r['state'] === 'critical' && $r['active'] && $r['kind'] !== 'semi'));
        foreach (array_slice($crit, 0, 2) as $c) {
            $out[] = ['alert', 'danger', t('rep.att_stock', ['name' => $c['name'], 'qty' => Stock::qty((float) $c['on_hand']) . ' ' . Stock::unitLabel($c['unit'])])];
        }
        $v = self::voidTotals($from, $to);
        if ($v['n'] > 0) {
            $top = Db::row("SELECT t.number, COUNT(*) AS n FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN tables t ON t.id = o.table_id
                WHERE i.status = 'void' AND i.sent_at IS NOT NULL AND i.void_at >= ? AND i.void_at < ? AND t.number IS NOT NULL GROUP BY o.table_id ORDER BY n DESC LIMIT 1", [$from, $to]);
            $out[] = ['x-circle', 'danger', t('rep.att_voids', ['n' => digits($v['n']), 'amount' => money($v['amount'])])
                . ($top && (int) $top['n'] > 1 ? ' · ' . t('rep.att_voids_t', ['n' => digits((int) $top['n']), 't' => digits((string) $top['number'])]) : '')];
        }
        $d = self::discountsGiven($from, $to);
        if ($d['n'] > 0) {
            $out[] = ['percent', 'warning', t('rep.att_disc', ['n' => digits($d['n']), 'amount' => money($d['amount'])])];
        }
        foreach (Rates::detail() as $cur => $r) {
            if ($r['accepted'] && $r['at'] && date('Y-m-d', intdiv((int) $r['at'], 1000)) !== date('Y-m-d')) {
                $out[] = ['currency', 'warning', t('rep.att_rate', ['cur' => t('cur.' . $cur)])];
            }
        }
        $s = \Sofrexa\Sync\Status::get();
        if ($s['configured']) {
            $out[] = $s['state'] === 'offline'
                ? ['wifi-off', 'danger', t('rep.att_sync_off', ['t' => $s['last_ok'] ? when_label($s['last_ok']) : '—'])]
                : ['cloud-check', 'success', t('rep.att_sync', ['t' => self::ago((int) $s['last_ok'])])];
        }
        return $out;
    }

    /** "5 sn önce", "3 dk önce". */
    public static function ago(int $ms): string
    {
        $s = max(0, intdiv(Clock::ms() - $ms, 1000));
        return $s < 60 ? t('set.sync.ago_s', ['n' => digits($s)]) : ($s < 3600 ? t('set.sync.ago_m', ['n' => digits(intdiv($s, 60))]) : t('set.sync.ago_h', ['n' => digits(intdiv($s, 3600))]));
    }

    /** Critical stock items (for the phone banner). */
    public static function critical(): array
    {
        return array_values(array_filter(Stock::items(), static fn(array $r): bool => $r['state'] === 'critical' && $r['active'] && $r['kind'] !== 'semi'));
    }

    /** Signed % text: "+%5,8", "−%3". */
    public static function pct(?float $v, bool $sign = true): string
    {
        if ($v === null) {
            return '';
        }
        $s = I18n::numAuto(abs($v), abs($v) < 10 ? 1 : 0);
        return ($sign ? ($v < 0 ? '−' : '+') : '') . '%' . digits($s);
    }
}
