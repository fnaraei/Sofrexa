<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Kitchen;

use Sofrexa\Core\{Audit, Clock, Db, Settings};
use Sofrexa\Modules\Orders\{Board, Notify, Orders};

/**
 * Kitchen and bar screens (K1 TV, K2 chef phone, K3 chef desktop). A ticket is one round of one order for
 * one station: the lines sent together. States as on KDS/TicketHeader: new (just arrived, sound) → cooking
 * ("Başla") → late (over the target time) → ready ("Hazır": the waiter's phone is told). A ready ticket
 * stays on the screen until the waiter takes the plates ("Aldım"). Tapping an item marks it plated.
 */
final class Kitchen
{
    public const STATIONS = ['kitchen', 'bar'];

    /** Target preparation time; after it a ticket is late (red). */
    public static function target(): int
    {
        return max(3, (int) Settings::get('kds.late_minutes', 15));
    }

    /**
     * Open tickets of a station ('' = all), oldest first. Each: key, order_id, round, station, title, area,
     * meta, sent_at, started, lines, state (new | cooking | late | ready), seconds (timer), ready_at.
     */
    public static function tickets(string $station = ''): array
    {
        $where = "l.deleted = 0 AND l.sent_at IS NOT NULL AND o.deleted = 0 AND o.status IN ('open', 'billed', 'pending', 'paid')
            AND l.status IN ('sent', 'ready') AND l.sent_at > ?";
        $p = [Clock::ms() - 12 * 3_600_000];
        if ($station !== '') {
            $where .= ' AND l.station = ?';
            $p[] = $station;
        }
        $rows = Db::rows("SELECT l.*, o.no, o.channel, o.table_id, o.label, o.guests, o.waiter_id, o.qr_session_id, o.approved_by, o.delivery,
                t.number AS table_no, a.name AS area_name, a.names AS area_names, u.name AS waiter_name, c.name AS customer_name, ap.name AS approver_name
            FROM order_items l JOIN orders o ON o.id = l.order_id LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = o.waiter_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users ap ON ap.id = o.approved_by
            WHERE $where ORDER BY l.sent_at, l.created_at", $p);
        $tickets = [];
        foreach ($rows as $l) {
            $key = $l['order_id'] . '|' . $l['round'] . '|' . $l['station'];
            $tickets[$key] ??= ['key' => $key, 'order_id' => $l['order_id'], 'round' => (int) $l['round'], 'station' => $l['station'], 'row' => $l, 'lines' => []];
            $tickets[$key]['lines'][] = $l;
        }
        $now = Clock::ms();
        $out = [];
        foreach ($tickets as $t) {
            $r = $t['row'];
            unset($t['row']);
            $open = array_filter($t['lines'], static fn(array $l): bool => $l['status'] === 'sent');
            $t['sent_at'] = min(array_map(static fn(array $l): int => (int) $l['sent_at'], $t['lines']));
            $t['started'] = (bool) array_filter($t['lines'], static fn(array $l): bool => (bool) $l['started_at']);
            $t['ready_at'] = $open ? null : max(array_map(static fn(array $l): int => (int) $l['ready_at'], $t['lines']));
            $t['seconds'] = intdiv(($t['ready_at'] ?? $now) - $t['sent_at'], 1000);
            $t['state'] = !$open ? 'ready' : ($t['seconds'] >= self::target() * 60 ? 'late' : ($t['started'] ? 'cooking' : 'new'));
            [$t['title'], $t['area'], $t['meta'], $t['channel_label']] = self::labels($r, $t);
            $t['no'] = (int) $r['no'];
            $t['channel'] = $r['channel'];
            $t['waiter'] = first_name($r['waiter_name'] ?? '');
            $out[] = $t;
        }
        return $out;
    }

    /** Title ("MASA 8"), area ("Salon", "Bahçe · QR", "Gel-al 19:45", "Levent"), meta line and the channel name for K3. */
    private static function labels(array $r, array $t): array
    {
        $d = json_arr($r['delivery']);
        $no = '#' . sprintf('%04d', (int) $r['no']);
        $area = $r['area_name'] ? tn(json_arr($r['area_names']) ?: $r['area_name']) : '';
        $waiter = first_name($r['waiter_name'] ?? '');
        $ready = $t['state'] ?? null;
        switch ($r['channel']) {
            case 'table':
            case 'qr':
                $isQr = $r['channel'] === 'qr' || $r['qr_session_id'];
                $title = mb_strtoupper(t('order.table', ['n' => $r['table_no']]), 'UTF-8');
                $meta = $isQr && $r['approver_name'] ? [t('kds.approved_by', ['name' => first_name($r['approver_name'])]), $no] : [$waiter, $no, t('kds.guests', ['n' => max(1, (int) $r['guests'])])];
                return [$title, $area . ($isQr ? ' · QR' : ''), $meta, $isQr ? 'QR' : t('kds.ch_table')];
            case 'takeaway':
                return [mb_strtoupper(t('order.takeaway', ['no' => sprintf('%04d', (int) $r['no'])]), 'UTF-8'), t('deliv.t_pickup') . (!empty($d['pickup_at']) ? ' ' . $d['pickup_at'] : ''),
                    [$waiter ?: ($r['customer_name'] ?? ''), date('H:i', intdiv((int) $r['sent_at'], 1000))], t('deliv.t_pickup')];
            case 'delivery':
                $courier = !empty($d['courier_id']) ? first_name((string) Db::value('SELECT name FROM users WHERE id = ?', [$d['courier_id']])) : '';
                $district = trim(explode(',', (string) ($d['address'] ?? ''))[0]);
                return [mb_strtoupper(t('order.delivery', ['no' => sprintf('%04d', (int) $r['no'])]), 'UTF-8'), $district,
                    [$waiter, $courier !== '' ? t('cash.courier', ['name' => $courier]) : ''], t('kds.ch_phone')];
            default:
                if (($d['type'] ?? '') === 'delivery') {
                    // online delivery: the district, as for a phone order
                    return [mb_strtoupper(t('order.online', ['no' => sprintf('%04d', (int) $r['no'])]), 'UTF-8'), trim(explode(',', (string) ($d['address'] ?? ''))[0]),
                        [\Sofrexa\Modules\Orders\Till::shortName($r['customer_name'] ?? $r['label'] ?? ''), !empty($d['pickup_at']) ? $d['pickup_at'] : ''], t('cash.f_online')];
                }
                return [mb_strtoupper(t('order.online', ['no' => sprintf('%04d', (int) $r['no'])]), 'UTF-8'), t('deliv.t_pickup') . (!empty($d['pickup_at']) ? ' ' . $d['pickup_at'] : ''),
                    [\Sofrexa\Modules\Orders\Till::shortName($r['customer_name'] ?? $r['label'] ?? '')], t('cash.f_online')];
        }
    }

    /** "Başla": New → Cooking. */
    public static function start(string $orderId, int $round, string $station): int
    {
        $lines = self::open($orderId, $round, $station);
        foreach ($lines as $l) {
            if (!$l['started_at']) {
                Db::save('order_items', ['id' => $l['id'], 'started_at' => Clock::ms()]);
            }
        }
        return count($lines);
    }

    /** Tap on an item: plated (ready) or back. The ticket's last item works like "Hazır". */
    public static function toggleLine(string $lineId): string
    {
        $l = Db::row('SELECT * FROM order_items WHERE id = ? AND deleted = 0', [$lineId]);
        if (!$l || !in_array($l['status'], ['sent', 'ready'], true)) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        if ($l['status'] === 'ready') {
            Db::save('order_items', ['id' => $lineId, 'status' => 'sent', 'ready_at' => null]);
            return 'sent';
        }
        if (count(self::open($l['order_id'], (int) $l['round'], $l['station'])) <= 1) {
            self::ready($l['order_id'], (int) $l['round'], $l['station']);
            return 'ready';
        }
        Db::save('order_items', ['id' => $lineId, 'status' => 'ready', 'ready_at' => Clock::ms(), 'started_at' => $l['started_at'] ?: Clock::ms()]);
        return 'ready';
    }

    /** "Hazır": every open line of the ticket is ready and the waiter is told (the whole ticket). Returns the lines marked. */
    public static function ready(string $orderId, int $round, string $station): int
    {
        $open = self::open($orderId, $round, $station);
        $now = Clock::ms();
        Db::tx(static function () use ($open, $now): void {
            foreach ($open as $l) {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'ready', 'ready_at' => $now, 'started_at' => $l['started_at'] ?: $now]);
            }
        });
        self::tell($orderId, $round, $station);
        return count($open);
    }

    /** "Garsonu tekrar çağır": the alert again. */
    public static function callAgain(string $orderId, int $round, string $station): void
    {
        self::tell($orderId, $round, $station);
    }

    private static function tell(string $orderId, int $round, string $station): void
    {
        $lines = Db::rows("SELECT * FROM order_items WHERE order_id = ? AND round = ? AND station = ? AND status = 'ready' AND deleted = 0", [$orderId, $round, $station]);
        if (!$lines) {
            return;
        }
        $o = Orders::get($orderId);
        $what = implode(', ', array_map(static fn(array $l): string => $l['name'] . ((float) $l['qty'] > 1 ? ' ×' . Orders::qtyText((float) $l['qty']) : ''), $lines));
        $params = ['what' => $what, 'text' => $what . ' · ' . ($station === 'bar' ? 'bar' : 'mutfak'), 'lines' => array_column($lines, 'id'),
            // W12: the full-screen alert on the waiter's phone shows the plates one per row, and where to carry them
            'station' => $station, 'area' => json_arr((string) $o['area_names']) ?: (string) ($o['area_name'] ?? ''),
            'items' => array_map(static fn(array $l): array => ['id' => $l['id'], 'name' => $l['name'], 'qty' => Orders::qtyText((float) $l['qty'])], $lines)];
        if (in_array($o['channel'], ['table', 'qr'], true)) {
            Notify::toWaiter('ready', $o, $params);
        } else {
            // takeaway / delivery / online: the till prepares the bag and the courier
            Notify::push('ready', $params + ['where' => Orders::where($o)], null, 'cashier', $orderId);
        }
    }

    /** Undo "Hazır" (a wrong tap) while the plates are still in the kitchen. */
    public static function recall(string $orderId, int $round, string $station): int
    {
        $lines = Db::rows("SELECT id FROM order_items WHERE order_id = ? AND round = ? AND station = ? AND status = 'ready' AND deleted = 0", [$orderId, $round, $station]);
        foreach ($lines as $l) {
            Db::save('order_items', ['id' => $l['id'], 'status' => 'sent', 'ready_at' => null]);
        }
        if ($lines) {
            // only these plates leave the alert: another round still waiting at the pass keeps ringing
            self::retell($orderId);
            Audit::log('kitchen.recall', Orders::where(Orders::get($orderId)) . ' · ' . count($lines) . ' ürün geri alındı', 'order', $orderId);
        }
        return count($lines);
    }

    /**
     * The ready alert of an order made to match the plates really waiting for it: after the kitchen took some back, or a
     * split or a merge moved plates to another bill. Plates no longer ready here leave its alert (which closes when
     * empty, without ringing again); ready plates it does not list yet — ones that came over from another bill — are
     * put on it, and for those it rings. So a plate at the pass always has one alert, on the bill it belongs to.
     */
    public static function retell(string $orderId): void
    {
        $ready = Db::rows("SELECT id, round, station FROM order_items WHERE order_id = ? AND status = 'ready' AND deleted = 0", [$orderId]);
        $listed = Notify::keepLines($orderId, array_column($ready, 'id'));
        $groups = [];
        foreach ($ready as $l) {
            if (!in_array((string) $l['id'], $listed, true)) {
                $groups[$l['round'] . '|' . $l['station']] = [(int) $l['round'], (string) $l['station']];
            }
        }
        foreach ($groups as [$round, $station]) {
            self::tell($orderId, $round, $station);
        }
    }

    /** The waiter took the plates ("Aldım"): ready lines become served and leave the screens. */
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

    private static function open(string $orderId, int $round, string $station): array
    {
        return Db::rows("SELECT * FROM order_items WHERE order_id = ? AND round = ? AND station = ? AND status = 'sent' AND deleted = 0", [$orderId, $round, $station]);
    }

    /**
     * Counts for the headers: open tickets per station, average minutes today, late, ready (not taken),
     * and for K3 one row per order with the progress per station.
     */
    public static function overview(?array $tickets = null): array
    {
        $tickets ??= self::tickets();
        $st = ['kitchen' => 0, 'bar' => 0];
        $late = $ready = [];
        foreach ($tickets as $t) {
            if ($t['state'] !== 'ready') {
                $st[$t['station']] = ($st[$t['station']] ?? 0) + 1;
            }
            if ($t['state'] === 'late') {
                $late[] = $t;
            }
            if ($t['state'] === 'ready') {
                $ready[] = $t;
            }
        }
        $orders = [];
        foreach ($tickets as $t) {
            $o = &$orders[$t['order_id']];
            $o ??= ['title' => Board::title(['channel' => $t['channel'], 'table_no' => $t['lines'][0]['table_no'] ?? null, 'area_name' => $t['lines'][0]['area_name'] ?? null,
                    'area_names' => $t['lines'][0]['area_names'] ?? null, 'no' => $t['no']]), 'channel' => $t['channel_label'], 'waiter' => $t['waiter'],
                'kitchen' => null, 'bar' => null, 'seconds' => 0, 'state' => 'ready', 'sent_at' => $t['sent_at']];
            foreach ($t['lines'] as $l) {
                $o[$t['station']] ??= [0, 0];
                $o[$t['station']][1]++;
                if ($l['status'] === 'ready') {
                    $o[$t['station']][0]++;
                }
            }
            $o['seconds'] = max($o['seconds'], $t['seconds']);
            $o['sent_at'] = min($o['sent_at'], $t['sent_at']);
            $rank = ['late' => 3, 'new' => 2, 'cooking' => 1, 'ready' => 0];
            if ($rank[$t['state']] > $rank[$o['state']]) {
                $o['state'] = $t['state'];
            }
            unset($o);
        }
        uasort($orders, static fn(array $a, array $b): int => $a['sent_at'] <=> $b['sent_at']);
        return [
            'stations' => $st,
            'open' => $st['kitchen'] + $st['bar'],
            'late' => $late,
            'ready' => $ready,
            'avg' => self::averageMinutes(),
            'orders' => array_values($orders),
        ];
    }

    /** Average minutes from sent to ready today (all stations, or one). */
    public static function averageMinutes(string $station = ''): int
    {
        [$from] = Clock::dayRange(Orders::businessDay(), (int) Settings::get('day.rollover_hour', 5));
        $v = Db::value('SELECT AVG(ready_at - sent_at) FROM order_items WHERE ready_at IS NOT NULL AND sent_at >= ? AND deleted = 0' . ($station !== '' ? ' AND station = ?' : ''), $station !== '' ? [$from, $station] : [$from]);
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
