<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Kitchen;

use Sofrexa\Core\{Audit, Clock, Db, Settings};
use Sofrexa\Modules\Orders\{Board, Notify, Orders};

/**
 * Kitchen and bar screens (K1 TV, K2 chef phone, K3 chef desktop). A ticket is one round of one order
 * for one station: the lines sent together. "Ready" marks the lines ready and tells the table's waiter;
 * a ticket stays on the screen, greyed, for a short while so a wrong tap can be undone.
 */
final class Kitchen
{
    public const STATIONS = ['kitchen', 'bar'];

    /** Minutes after which a ticket counts as late (orange) and very late (red). */
    public static function lateAfter(): int
    {
        return max(5, (int) Settings::get('kds.late_minutes', 15));
    }

    public static function warnAfter(): int
    {
        return max(3, (int) Settings::get('kds.warn_minutes', 10));
    }

    /**
     * Open tickets of a station ('' = all), oldest first. Each: order, round, station, where, waiter,
     * sent_at, lines (with status), state new | cooking | late | ready, ready_at.
     * $recentReady: also return tickets that became ready in the last N seconds (to undo).
     */
    public static function tickets(string $station = '', int $recentReady = 90): array
    {
        $where = "l.deleted = 0 AND l.sent_at IS NOT NULL AND o.deleted = 0 AND o.status IN ('open', 'billed', 'pending')
            AND (l.status = 'sent' OR (l.status = 'ready' AND l.ready_at > ?) OR (l.status = 'void' AND l.void_at > l.sent_at AND l.void_at > ?))";
        $p = [Clock::ms() - $recentReady * 1000, Clock::ms() - 600_000];
        if ($station !== '') {
            $where .= ' AND l.station = ?';
            $p[] = $station;
        }
        $rows = Db::rows("SELECT l.*, o.no, o.channel, o.table_id, o.label, o.guests, o.waiter_id, o.opened_at AS order_opened, o.delivery,
                t.number AS table_no, a.name AS area_name, a.names AS area_names, u.name AS waiter_name, c.name AS customer_name
            FROM order_items l JOIN orders o ON o.id = l.order_id LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = o.waiter_id LEFT JOIN customers c ON c.id = o.customer_id
            WHERE $where ORDER BY l.sent_at, l.created_at", $p);
        $tickets = [];
        foreach ($rows as $l) {
            $key = $l['order_id'] . '|' . $l['round'] . '|' . $l['station'];
            if (!isset($tickets[$key])) {
                $tickets[$key] = [
                    'key' => $key, 'order_id' => $l['order_id'], 'round' => (int) $l['round'], 'station' => $l['station'],
                    'no' => (int) $l['no'], 'channel' => $l['channel'], 'table_no' => $l['table_no'], 'guests' => (int) $l['guests'],
                    'where' => Board::title(['channel' => $l['channel'], 'table_no' => $l['table_no'], 'area_name' => $l['area_name'], 'area_names' => $l['area_names'], 'no' => $l['no']], false),
                    'area' => $l['area_name'] ? tn(json_arr($l['area_names']) ?: $l['area_name']) : '',
                    'waiter' => first_name($l['waiter_name'] ?? ''), 'customer' => $l['customer_name'] ?? $l['label'],
                    'sent_at' => (int) $l['sent_at'], 'lines' => [], 'ready_at' => null,
                ];
            }
            $tickets[$key]['lines'][] = $l;
        }
        $now = Clock::ms();
        $out = [];
        foreach ($tickets as $t) {
            $live = array_filter($t['lines'], static fn(array $l): bool => $l['status'] !== 'void');
            if (!$live) {
                continue; // everything voided: nothing left to cook
            }
            $open = array_filter($live, static fn(array $l): bool => $l['status'] === 'sent');
            $mins = intdiv($now - $t['sent_at'], 60_000);
            if (!$open) {
                $t['state'] = 'ready';
                $t['ready_at'] = max(array_map(static fn(array $l): int => (int) $l['ready_at'], $live));
            } else {
                $t['state'] = $mins >= self::lateAfter() ? 'late' : ($mins >= self::warnAfter() ? 'warn' : ($mins < 2 ? 'new' : 'cooking'));
            }
            $t['minutes'] = $mins;
            $t['items'] = (int) array_sum(array_map(static fn(array $l): float => (float) $l['qty'], $live));
            $t['all_day'] = $t['round'] > 1;
            $out[] = $t;
        }
        return $out;
    }

    /** Marks lines ready (all open lines of a ticket, or the given ones) and tells the waiter. Returns the lines marked. */
    public static function ready(string $orderId, int $round, string $station, array $lineIds = []): int
    {
        $p = [$orderId, $round, $station];
        $sql = "SELECT * FROM order_items WHERE order_id = ? AND round = ? AND station = ? AND status = 'sent' AND deleted = 0";
        if ($lineIds) {
            $sql .= ' AND id IN (' . Db::in($lineIds) . ')';
            $p = [...$p, ...$lineIds];
        }
        $lines = Db::rows($sql, $p);
        if (!$lines) {
            return 0;
        }
        $now = Clock::ms();
        Db::tx(static function () use ($lines, $now): void {
            foreach ($lines as $l) {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'ready', 'ready_at' => $now]);
            }
        });
        $o = Orders::get($orderId);
        $what = implode(', ', array_map(static fn(array $l): string => $l['name'] . ((float) $l['qty'] > 1 ? ' ×' . Orders::qtyText((float) $l['qty']) : ''), $lines));
        if (in_array($o['channel'], ['table', 'qr'], true)) {
            Notify::toWaiter('ready', $o, ['what' => $what, 'text' => $what . ' · ' . ($station === 'bar' ? 'bar' : 'mutfak'), 'lines' => array_column($lines, 'id')]);
        } else {
            // takeaway / delivery: the till prepares the bag and the courier
            Notify::push('ready', ['where' => Orders::where($o), 'what' => $what, 'text' => $what, 'lines' => array_column($lines, 'id')], null, 'cashier', $orderId);
        }
        return count($lines);
    }

    /** Undo "ready" (a wrong tap) while the ticket is still on the screen. */
    public static function recall(string $orderId, int $round, string $station): int
    {
        $lines = Db::rows("SELECT id FROM order_items WHERE order_id = ? AND round = ? AND station = ? AND status = 'ready' AND deleted = 0", [$orderId, $round, $station]);
        foreach ($lines as $l) {
            Db::save('order_items', ['id' => $l['id'], 'status' => 'sent', 'ready_at' => null]);
        }
        if ($lines) {
            Notify::closeFor($orderId, 'ready');
            Audit::log('kitchen.recall', Orders::where(Orders::get($orderId)) . ' · ' . count($lines) . ' ürün geri alındı', 'order', $orderId);
        }
        return count($lines);
    }

    /** The waiter took the plates ("Aldım"): ready lines become served. */
    public static function served(string $orderId, array $lineIds = []): void
    {
        $sql = "SELECT id FROM order_items WHERE order_id = ? AND status = 'ready' AND deleted = 0";
        $p = [$orderId];
        if ($lineIds) {
            $sql .= ' AND id IN (' . Db::in($lineIds) . ')';
            $p = [...$p, ...$lineIds];
        }
        foreach (Db::rows($sql, $p) as $l) {
            Db::save('order_items', ['id' => $l['id'], 'status' => 'served', 'served_at' => Clock::ms()]);
        }
    }

    /**
     * Chef overview (K2/K3): per station open tickets, items, late count and today's average preparation time;
     * and the "all day" list: open quantities per item across tickets.
     */
    public static function overview(): array
    {
        $tickets = self::tickets('', 0);
        $stations = [];
        foreach (self::STATIONS as $s) {
            $mine = array_filter($tickets, static fn(array $t): bool => $t['station'] === $s && $t['state'] !== 'ready');
            $stations[$s] = [
                'tickets' => count($mine),
                'items' => array_sum(array_column($mine, 'items')),
                'late' => count(array_filter($mine, static fn(array $t): bool => $t['state'] === 'late')),
                'avg' => self::averageMinutes($s),
            ];
        }
        $allDay = [];
        foreach ($tickets as $t) {
            foreach ($t['lines'] as $l) {
                if ($l['status'] === 'sent') {
                    $allDay[$l['name']] = ($allDay[$l['name']] ?? 0) + (float) $l['qty'];
                }
            }
        }
        arsort($allDay);
        return ['stations' => $stations, 'tickets' => $tickets, 'all_day' => $allDay];
    }

    /** Average minutes from sent to ready today for a station. */
    public static function averageMinutes(string $station): int
    {
        [$from] = Clock::dayRange(Orders::businessDay(), (int) Settings::get('day.rollover_hour', 5));
        $v = Db::value("SELECT AVG(ready_at - sent_at) FROM order_items WHERE station = ? AND ready_at IS NOT NULL AND sent_at >= ? AND deleted = 0", [$station, $from]);
        return $v ? (int) round((float) $v / 60_000) : 0;
    }

    /** Shared screen token for the TV: /kds/{token} works without a login, for the kitchen display only. */
    public static function displayToken(bool $renew = false): string
    {
        $t = (string) Settings::get('kds.token', '');
        if ($t === '' || $renew) {
            $t = bin2hex(random_bytes(12));
            Settings::set('kds.token', $t);
            if ($renew) {
                Audit::log('settings.kds_token', 'mutfak ekranı bağlantısı yenilendi', 'settings', 'kds');
            }
        }
        return $t;
    }

    public static function checkToken(string $token): bool
    {
        $t = (string) Settings::get('kds.token', '');
        return $t !== '' && hash_equals($t, $token);
    }
}
