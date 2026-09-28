<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, HttpError, I18n, Money, Settings, ValidationError};
use Sofrexa\Modules\Menu\Menu;

/**
 * Orders: table, QR, takeaway, delivery and online. Lines go new → sent → ready → served (or void with a reason).
 * Money is integer kuruş, prices include VAT; totals are recalculated from the lines after every change.
 * An order is numbered per business day; orders made on the web copy (QR, online) use numbers from 5001
 * so they never collide with the till's.
 */
final class Orders
{
    public const OPEN = ['pending', 'open', 'billed'];

    public static function businessDay(?int $ms = null): string
    {
        return Clock::day($ms ?? Clock::ms(), (int) Settings::get('day.rollover_hour', 5));
    }

    // ------------------------------------------------------------ reading

    public static function get(string $id): array
    {
        $o = Db::row('SELECT o.*, t.number AS table_no, t.area_id, a.name AS area_name, a.names AS area_names, u.name AS waiter_name,
                c.name AS customer_name, c.phone AS customer_phone
            FROM orders o LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = o.waiter_id LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.id = ? AND o.deleted = 0', [$id]);
        if (!$o) {
            throw new HttpError(404);
        }
        $o['lines'] = self::lines($id);
        $o['discounts'] = Db::rows('SELECT d.*, u.name AS user_name FROM order_discounts d LEFT JOIN users u ON u.id = d.user_id WHERE d.order_id = ? ORDER BY d.at', [$id]);
        $o['payments'] = Db::rows('SELECT p.*, u.name AS user_name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.order_id = ? ORDER BY p.at', [$id]);
        $o['delivery'] = json_arr($o['delivery']);
        $o['due'] = max(0, (int) $o['total'] - (int) $o['paid']);
        return $o;
    }

    public static function lines(string $orderId): array
    {
        return Db::rows('SELECT l.*, u.name AS by_name FROM order_items l LEFT JOIN users u ON u.id = l.created_by
            WHERE l.order_id = ? AND l.deleted = 0 ORDER BY l.round, l.created_at', [$orderId]);
    }

    /** Open order of a table (the newest if a bill was split). */
    public static function openForTable(string $tableId): ?array
    {
        $id = Db::value("SELECT id FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 ORDER BY parent_id IS NOT NULL, opened_at LIMIT 1", [$tableId]);
        return $id ? self::get($id) : null;
    }

    /** All open orders with totals and line counts (till list, table map). */
    public static function open(array $channels = []): array
    {
        $where = "o.status IN ('pending', 'open', 'billed') AND o.deleted = 0";
        $p = [];
        if ($channels) {
            $where .= ' AND o.channel IN (' . Db::in($channels) . ')';
            $p = $channels;
        }
        return Db::rows("SELECT o.*, t.number AS table_no, t.area_id, a.name AS area_name, a.names AS area_names, u.name AS waiter_name, c.name AS customer_name,
                (SELECT COUNT(*) FROM order_items l WHERE l.order_id = o.id AND l.deleted = 0 AND l.status <> 'void') AS line_count,
                (SELECT COUNT(*) FROM order_items l WHERE l.order_id = o.id AND l.deleted = 0 AND l.status = 'new') AS unsent,
                (SELECT COUNT(*) FROM order_items l WHERE l.order_id = o.id AND l.deleted = 0 AND l.status = 'ready') AS ready,
                (SELECT MIN(l.sent_at) FROM order_items l WHERE l.order_id = o.id AND l.deleted = 0 AND l.status = 'sent') AS oldest_sent
            FROM orders o LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id
            LEFT JOIN users u ON u.id = o.waiter_id LEFT JOIN customers c ON c.id = o.customer_id
            WHERE $where ORDER BY o.opened_at", $p);
    }

    // ------------------------------------------------------------ creating

    public static function create(string $channel, array $attrs = []): string
    {
        if (!in_array($channel, ['table', 'qr', 'takeaway', 'delivery', 'online'], true)) {
            throw new \InvalidArgumentException('channel');
        }
        $day = self::businessDay();
        $base = App::isWeb() ? 5000 : 0;
        $no = (int) Db::value('SELECT COALESCE(MAX(no), ?) + 1 FROM orders WHERE day = ? AND no > ? AND no <= ?', [$base, $day, $base, $base + 4999]);
        $u = Auth::user();
        return Db::save('orders', [
            'no' => $no,
            'day' => $day,
            'channel' => $channel,
            'status' => $attrs['status'] ?? 'open',
            'table_id' => $attrs['table_id'] ?? null,
            'qr_session_id' => $attrs['qr_session_id'] ?? null,
            'customer_id' => $attrs['customer_id'] ?? null,
            'waiter_id' => $attrs['waiter_id'] ?? ($u['id'] ?? null),
            'guests' => (int) ($attrs['guests'] ?? 0),
            'opened_at' => Clock::ms(),
            'note' => $attrs['note'] ?? null,
            'label' => $attrs['label'] ?? null,
            'delivery' => $attrs['delivery'] ?? null,
            'parent_id' => $attrs['parent_id'] ?? null,
            'shift_id' => Shifts::currentId(),
        ]);
    }

    /** The table's open order, or a new one. */
    public static function forTable(string $tableId, int $guests = 0): string
    {
        if (!Db::value('SELECT 1 FROM tables WHERE id = ? AND deleted = 0', [$tableId])) {
            throw new HttpError(404);
        }
        $open = Db::value("SELECT id FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 ORDER BY opened_at LIMIT 1", [$tableId]);
        return $open ?: self::create('table', ['table_id' => $tableId, 'guests' => $guests]);
    }

    // ------------------------------------------------------------ lines

    /**
     * Adds an item (status new: not yet sent). $mods = modifier ids. Returns the line id.
     * Same item + same options + same note on an unsent line only raises the quantity.
     */
    public static function addItem(string $orderId, string $itemId, float $qty = 1, array $mods = [], string $note = ''): string
    {
        $o = self::editable($orderId);
        $item = Menu::get($itemId);
        if (!$item['orderable']) {
            throw new \InvalidArgumentException(I18n::t('order.err_soldout', ['name' => tn($item['names'])]));
        }
        $qty = max(0.5, min(99, $qty));
        if ($item['left'] !== null && $qty > $item['left']) {
            throw new \InvalidArgumentException(I18n::t('order.err_stock', ['name' => tn($item['names']), 'n' => $item['left']]));
        }
        [$modRows, $modsPrice] = self::mods($itemId, $mods);
        $note = mb_substr(trim($note), 0, 200);
        $same = Db::value("SELECT id FROM order_items WHERE order_id = ? AND item_id = ? AND status = 'new' AND deleted = 0 AND mods = ? AND COALESCE(note, '') = ?",
            [$orderId, $itemId, json_encode($modRows, JSON_UNESCAPED_UNICODE), $note]);
        if ($same) {
            Db::exec('UPDATE order_items SET qty = qty + ?, updated_at = ? WHERE id = ?', [$qty, Clock::ms(), $same]);
            \Sofrexa\Core\Sync::touch('order_items', $same);
            self::recalc($orderId);
            return $same;
        }
        $names = json_arr($item['names']);
        $id = Db::save('order_items', [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'name' => $names['tr'] ?? tn($names),
            'qty' => $qty,
            'unit_price' => (int) $item['price'],
            'mods' => $modRows,
            'mods_price' => $modsPrice,
            'note' => $note !== '' ? $note : null,
            'station' => $item['station_eff'],
            'round' => (int) Db::value('SELECT COALESCE(MAX(round), 0) + 1 FROM order_items WHERE order_id = ? AND status <> ?', [$orderId, 'new']),
            'status' => 'new',
            'vat_rate' => (float) $item['vat_eff'],
            'cost' => (int) $item['cost'],
            'created_by' => Auth::user()['id'] ?? null,
            'created_at' => Clock::ms(),
        ]);
        self::recalc($orderId);
        return $id;
    }

    /** Chosen options as [{id, name, price}] and their total price. */
    private static function mods(string $itemId, array $mods): array
    {
        $mods = array_values(array_unique(array_filter(array_map('strval', $mods))));
        if (!$mods) {
            return [[], 0];
        }
        $rows = Db::rows('SELECT m.id, m.names, m.price, m.group_id FROM modifiers m JOIN item_modifier_groups g ON g.group_id = m.group_id AND g.item_id = ? AND g.deleted = 0
            WHERE m.id IN (' . Db::in($mods) . ') AND m.deleted = 0', [$itemId, ...$mods]);
        $out = [];
        $sum = 0;
        foreach ($rows as $m) {
            $out[] = ['id' => $m['id'], 'name' => tn($m['names'], 'tr'), 'price' => (int) $m['price']];
            $sum += (int) $m['price'];
        }
        return [$out, $sum];
    }

    /** Quantity or note of an unsent line; quantity 0 removes it. */
    public static function updateLine(string $lineId, ?float $qty = null, ?string $note = null): void
    {
        $l = self::line($lineId);
        self::editable($l['order_id']);
        if ($l['status'] !== 'new') {
            throw new \InvalidArgumentException(I18n::t('order.err_sent'));
        }
        if ($qty !== null && $qty <= 0) {
            Db::softDelete('order_items', $lineId);
        } else {
            $row = ['id' => $lineId];
            if ($qty !== null) {
                $row['qty'] = min(99, $qty);
            }
            if ($note !== null) {
                $row['note'] = mb_substr(trim($note), 0, 200) ?: null;
            }
            Db::save('order_items', $row);
        }
        self::recalc($l['order_id']);
    }

    /** Sends every unsent line to the kitchen/bar (tickets per station). Returns the number of lines sent. */
    public static function send(string $orderId): int
    {
        $o = self::editable($orderId);
        $new = Db::rows("SELECT * FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId]);
        if (!$new) {
            return 0;
        }
        $round = (int) Db::value("SELECT COALESCE(MAX(round), 0) + 1 FROM order_items WHERE order_id = ? AND status NOT IN ('new') AND deleted = 0", [$orderId]);
        $now = Clock::ms();
        Db::tx(static function () use ($new, $round, $now, $o): void {
            foreach ($new as $l) {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'sent', 'sent_at' => $now, 'round' => $round]);
            }
            if ($o['status'] === 'billed') {
                Db::save('orders', ['id' => $o['id'], 'status' => 'open']);
            }
        });
        Tickets::kitchen($orderId, array_column($new, 'id'), $round);
        return count($new);
    }

    /** Void a line. Sent lines need a reason and orders.void; the station gets a cancel ticket. */
    public static function voidLine(string $lineId, string $reason, float $qty = 0): void
    {
        $l = self::line($lineId);
        $o = self::editable($l['order_id']);
        if ($l['status'] === 'new') {
            self::updateLine($lineId, 0);
            return;
        }
        if (!Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $reason = mb_substr(trim($reason), 0, 120);
        if ($reason === '') {
            throw new ValidationError(['reason' => I18n::t('order.err_reason')]);
        }
        $qty = $qty > 0 && $qty < (float) $l['qty'] ? $qty : (float) $l['qty'];
        $u = Auth::user();
        Db::tx(static function () use ($l, $qty, $reason, $u): void {
            if ($qty < (float) $l['qty']) {
                // partial void: the rest stays as it is, the voided part becomes its own line
                Db::save('order_items', ['id' => $l['id'], 'qty' => (float) $l['qty'] - $qty]);
                $void = $l;
                unset($void['id'], $void['by_name']);
                Db::save('order_items', ['qty' => $qty, 'status' => 'void', 'void_reason' => $reason, 'void_by' => $u['id'] ?? null, 'void_at' => Clock::ms(), 'mods' => json_arr($l['mods'])] + $void);
            } else {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'void', 'void_reason' => $reason, 'void_by' => $u['id'] ?? null, 'void_at' => Clock::ms()]);
            }
        });
        self::recalc($o['id']);
        $amount = (int) round($qty * ((int) $l['unit_price'] + (int) $l['mods_price']));
        Audit::log('order.void_item', self::where($o) . ' · ' . $l['name'] . ' ×' . self::qtyText($qty) . ' · ' . Money::fmt($amount, false, 'tr') . ' · sebep: ' . $reason, 'order', $o['id'], ['line' => $l['id']]);
        Tickets::void($o['id'], $l['id'], $qty, $reason);
    }

    public static function discount(string $orderId, string $kind, float $value, string $reason = ''): void
    {
        if (!Auth::can('orders.discount')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $o = self::editable($orderId);
        $kind = $kind === 'amount' ? 'amount' : 'pct';
        if ($value <= 0 || ($kind === 'pct' && $value > 100)) {
            throw new ValidationError(['value' => I18n::t('order.err_discount')]);
        }
        $amount = $kind === 'pct' ? (int) round((int) $o['subtotal'] * $value / 100) : (int) round($value);
        $amount = min($amount, max(0, (int) $o['subtotal'] - (int) $o['discount']));
        Db::append('order_discounts', ['order_id' => $orderId, 'kind' => $kind, 'value' => $value, 'amount' => $amount, 'reason' => mb_substr($reason, 0, 120) ?: null, 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
        self::recalc($orderId);
        Audit::log('order.discount', self::where($o) . ' · ' . ($kind === 'pct' ? '%' . I18n::num($value, 0, 'tr') : Money::fmt((int) $value, false, 'tr')) . ' · ' . Money::fmt($amount, false, 'tr') . ($reason !== '' ? ' · ' . $reason : ''), 'order', $orderId);
    }

    /** Removes all discounts of an open order (a reverse entry keeps the history). */
    public static function clearDiscount(string $orderId): void
    {
        $o = self::editable($orderId);
        if ((int) $o['discount'] <= 0) {
            return;
        }
        Db::append('order_discounts', ['order_id' => $orderId, 'kind' => 'reverse', 'value' => 0, 'amount' => -(int) $o['discount'], 'reason' => 'iptal', 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
        self::recalc($orderId);
        Audit::log('order.discount', self::where($o) . ' · indirim kaldırıldı', 'order', $orderId);
    }

    // ------------------------------------------------------------ tables

    public static function moveTable(string $orderId, string $tableId): void
    {
        $o = self::editable($orderId);
        if (Db::value("SELECT 1 FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 AND id <> ?", [$tableId, $orderId])) {
            throw new \InvalidArgumentException(I18n::t('order.err_table_busy'));
        }
        $to = Db::value('SELECT number FROM tables WHERE id = ? AND deleted = 0', [$tableId]);
        if ($to === null) {
            throw new HttpError(404);
        }
        Db::save('orders', ['id' => $orderId, 'table_id' => $tableId]);
        Audit::log('order.transfer', self::where($o) . ' → Masa ' . $to, 'order', $orderId);
    }

    /** Moves every line (and payments) of $fromId into $intoId and closes $fromId. */
    public static function merge(string $fromId, string $intoId): void
    {
        $from = self::editable($fromId);
        $into = self::editable($intoId);
        if ((int) $from['paid'] > 0) {
            throw new \InvalidArgumentException(I18n::t('order.err_has_payments'));
        }
        Db::tx(static function () use ($fromId, $intoId): void {
            foreach (Db::rows('SELECT id FROM order_items WHERE order_id = ? AND deleted = 0', [$fromId]) as $l) {
                Db::save('order_items', ['id' => $l['id'], 'order_id' => $intoId]);
            }
            foreach (Db::rows('SELECT * FROM order_discounts WHERE order_id = ?', [$fromId]) as $d) {
                Db::append('order_discounts', ['order_id' => $intoId, 'kind' => $d['kind'], 'value' => $d['value'], 'amount' => $d['amount'], 'reason' => 'birleştirme: ' . ($d['reason'] ?? ''), 'user_id' => $d['user_id'], 'at' => Clock::ms()]);
            }
            Db::save('orders', ['id' => $fromId, 'status' => 'void', 'closed_at' => Clock::ms(), 'note' => 'merged:' . $intoId]);
        });
        self::recalc($intoId);
        Audit::log('order.merge', self::where($from) . ' → ' . self::where($into), 'order', $intoId);
    }

    public static function setWaiter(string $orderId, string $userId): void
    {
        $o = self::editable($orderId);
        $name = Db::value('SELECT name FROM users WHERE id = ? AND active = 1 AND deleted = 0', [$userId]);
        if ($name === null) {
            throw new HttpError(404);
        }
        Db::save('orders', ['id' => $orderId, 'waiter_id' => $userId]);
        Audit::log('order.transfer', self::where($o) . ' · garson → ' . $name, 'order', $orderId);
    }

    /** Moves the chosen lines to a separate bill of the same table (pay part of the table). Returns the new order id. */
    public static function split(string $orderId, array $lineIds): string
    {
        $o = self::editable($orderId);
        $lineIds = array_values(array_filter(array_map('strval', $lineIds)));
        $lines = $lineIds ? Db::rows('SELECT id FROM order_items WHERE order_id = ? AND id IN (' . Db::in($lineIds) . ") AND deleted = 0 AND status <> 'void'", [$orderId, ...$lineIds]) : [];
        if (!$lines) {
            throw new ValidationError(['lines' => I18n::t('order.err_pick_lines')]);
        }
        $new = self::create($o['channel'], ['table_id' => $o['table_id'], 'customer_id' => $o['customer_id'], 'waiter_id' => $o['waiter_id'], 'parent_id' => $orderId, 'label' => $o['label']]);
        foreach ($lines as $l) {
            Db::save('order_items', ['id' => $l['id'], 'order_id' => $new]);
        }
        self::recalc($orderId);
        self::recalc($new);
        return $new;
    }

    // ------------------------------------------------------------ bill and payment

    /** Prints the pre-bill (adisyon) and marks the order as billed. */
    public static function preBill(string $orderId): void
    {
        $o = self::editable($orderId);
        Db::save('orders', ['id' => $orderId, 'status' => $o['status'] === 'pending' ? 'pending' : 'billed', 'printed_bill' => (int) $o['printed_bill'] + 1]);
        Tickets::preBill($orderId);
    }

    /**
     * Takes payments. $parts: [['method' => cash|card|account, 'currency' => TRY|GBP|USD|EUR, 'amount' => kuruş (TRY)
     * or 'amount_fx' => float for foreign cash], ...]. Cash over the due amount becomes change (in lira).
     * When the order is fully paid it closes and the receipt prints. Returns ['change' => kuruş, 'paid' => bool].
     */
    public static function pay(string $orderId, array $parts, ?string $customerId = null, bool $receipt = true): array
    {
        $o = self::editable($orderId);
        if ((int) $o['total'] <= 0 && !$o['lines']) {
            throw new \InvalidArgumentException(I18n::t('order.err_empty'));
        }
        if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId])) {
            self::send($orderId);
            $o = self::get($orderId);
        }
        $due = max(0, (int) $o['total'] - (int) $o['paid']);
        $shift = Shifts::currentId();
        $rates = Rates::latest();
        $change = 0;
        $rows = [];
        $sum = 0;
        foreach ($parts as $p) {
            $method = in_array($p['method'] ?? '', ['cash', 'card', 'account'], true) ? $p['method'] : 'cash';
            $cur = strtoupper((string) ($p['currency'] ?? 'TRY'));
            if ($method !== 'cash') {
                $cur = 'TRY';
            }
            if ($cur !== 'TRY') {
                if (empty($rates[$cur])) {
                    throw new ValidationError(['currency' => I18n::t('order.err_rate', ['cur' => $cur])]);
                }
                $fx = round((float) ($p['amount_fx'] ?? 0), 2);
                $amount = Money::toTry($fx, $rates[$cur]);
                $rate = $rates[$cur];
            } else {
                $fx = 0.0;
                $amount = (int) ($p['amount'] ?? 0);
                $rate = 1.0;
            }
            if ($amount <= 0) {
                continue;
            }
            if ($method === 'account') {
                $customerId = $customerId ?: $o['customer_id'];
                Accounts::assertCredit($customerId, $amount);
            }
            $courier = $p['courier_id'] ?? null;
            $rows[] = compact('method', 'cur', 'fx', 'amount', 'rate', 'courier');
            $sum += $amount;
        }
        if (!$rows) {
            throw new ValidationError(['amount' => I18n::t('order.err_amount')]);
        }
        if ($sum > $due) {
            // only cash can be given back; card or account over the due amount is refused
            $nonCash = array_sum(array_map(static fn(array $r): int => $r['method'] === 'cash' ? 0 : $r['amount'], $rows));
            if ($nonCash > $due) {
                throw new ValidationError(['amount' => I18n::t('order.err_over')]);
            }
            $change = $sum - $due;
        }
        $u = Auth::user();
        Db::tx(static function () use ($rows, $orderId, $shift, $customerId, $u, $change, $o): void {
            $left = $change;
            foreach (array_reverse($rows, true) as $i => $r) {
                // the change is taken off the last cash part
                $give = $r['method'] === 'cash' && $left > 0 ? min($left, $r['amount']) : 0;
                $left -= $give;
                $rows[$i]['give'] = $give;
            }
            foreach ($rows as $r) {
                Db::append('payments', [
                    'order_id' => $orderId, 'shift_id' => $shift, 'method' => $r['method'], 'currency' => $r['cur'], 'amount_fx' => $r['fx'], 'rate' => $r['rate'],
                    'amount' => $r['amount'] - ($r['give'] ?? 0), 'change_given' => $r['give'] ?? 0, 'customer_id' => $r['method'] === 'account' ? $customerId : ($customerId ?: $o['customer_id']),
                    'at' => Clock::ms(), 'user_id' => $u['id'] ?? null, 'courier_id' => $r['courier'],
                ]);
                if ($r['method'] === 'account') {
                    Accounts::charge($customerId, $r['amount'], $orderId);
                }
            }
            if ($customerId && !$o['customer_id']) {
                Db::save('orders', ['id' => $orderId, 'customer_id' => $customerId]);
            }
        });
        $o = self::recalc($orderId);
        $closed = (int) $o['paid'] >= (int) $o['total'];
        if ($closed) {
            self::close($orderId);
        }
        if (array_filter($rows, static fn(array $r): bool => $r['method'] === 'cash')) {
            Tickets::drawer();
        }
        if ($closed && $receipt) {
            Tickets::receipt($orderId);
        }
        return ['change' => $change, 'paid' => $closed, 'due' => max(0, (int) $o['total'] - (int) $o['paid'])];
    }

    private static function close(string $orderId): void
    {
        Db::save('orders', ['id' => $orderId, 'status' => 'paid', 'closed_at' => Clock::ms()]);
        Db::exec("UPDATE order_items SET status = 'served', served_at = COALESCE(served_at, ?), updated_at = ? WHERE order_id = ? AND status IN ('sent', 'ready') AND deleted = 0", [Clock::ms(), Clock::ms(), $orderId]);
        foreach (Db::rows("SELECT id FROM order_items WHERE order_id = ? AND status = 'served'", [$orderId]) as $l) {
            \Sofrexa\Core\Sync::touch('order_items', $l['id']);
        }
        if (class_exists(\Sofrexa\Modules\Customers\Loyalty::class)) {
            \Sofrexa\Modules\Customers\Loyalty::earn($orderId);
        }
    }

    /** Cancels a whole open order with a reason (only when nothing was paid). */
    public static function void(string $orderId, string $reason): void
    {
        if (!Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $o = self::editable($orderId);
        if ((int) $o['paid'] > 0) {
            throw new \InvalidArgumentException(I18n::t('order.err_has_payments'));
        }
        $reason = trim($reason) ?: '—';
        foreach ($o['lines'] as $l) {
            if ($l['status'] !== 'void') {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'void', 'void_reason' => $reason, 'void_by' => Auth::user()['id'] ?? null, 'void_at' => Clock::ms()]);
            }
        }
        Db::save('orders', ['id' => $orderId, 'status' => 'void', 'closed_at' => Clock::ms(), 'note' => trim(($o['note'] ?? '') . ' · iptal: ' . $reason, ' ·')]);
        self::recalc($orderId);
        Audit::log('order.void', self::where($o) . ' · ' . Money::fmt((int) $o['total'], false, 'tr') . ' · sebep: ' . $reason, 'order', $orderId);
    }

    // ------------------------------------------------------------ helpers

    /** Recalculates subtotal, discount, total and paid from lines, discounts and payments. */
    public static function recalc(string $orderId): array
    {
        $sub = (int) Db::value("SELECT COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void'", [$orderId]);
        $disc = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM order_discounts WHERE order_id = ?', [$orderId]);
        $disc = max(0, min($disc, $sub));
        $paid = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ?', [$orderId]);
        Db::save('orders', ['id' => $orderId, 'subtotal' => $sub, 'discount' => $disc, 'total' => $sub - $disc, 'paid' => $paid]);
        return Db::row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    }

    /** VAT per rate for the receipt and reports: [rate => [gross, vat]]. */
    public static function vat(string $orderId): array
    {
        $o = Db::row('SELECT subtotal, discount FROM orders WHERE id = ?', [$orderId]);
        $factor = (int) $o['subtotal'] > 0 ? 1 - (int) $o['discount'] / (int) $o['subtotal'] : 1;
        $out = [];
        foreach (Db::rows("SELECT vat_rate, SUM(ROUND(qty * (unit_price + mods_price))) AS gross FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void' GROUP BY vat_rate", [$orderId]) as $r) {
            $gross = (int) round((int) $r['gross'] * $factor);
            $out[(string) (float) $r['vat_rate']] = [$gross, Money::vatOf($gross, (float) $r['vat_rate'])];
        }
        return $out;
    }

    public static function line(string $id): array
    {
        $l = Db::row('SELECT * FROM order_items WHERE id = ? AND deleted = 0', [$id]);
        if (!$l) {
            throw new HttpError(404);
        }
        return $l;
    }

    /** The order, if it can still change (not paid or void). */
    public static function editable(string $orderId): array
    {
        $o = self::get($orderId);
        if (!in_array($o['status'], self::OPEN, true)) {
            throw new \InvalidArgumentException(I18n::t('order.err_closed'));
        }
        return $o;
    }

    /** "Masa 8", "Paket #12 · Ayşe", "Teslimat #14" — for tickets and the activity log (Turkish). */
    public static function where(array $o): string
    {
        return match ($o['channel']) {
            'table', 'qr' => 'Masa ' . ($o['table_no'] ?? Db::value('SELECT number FROM tables WHERE id = ?', [$o['table_id']]) ?? '?'),
            'takeaway' => 'Paket #' . $o['no'] . ($o['label'] ? ' · ' . $o['label'] : ''),
            'delivery' => 'Teslimat #' . $o['no'],
            default => 'Online #' . $o['no'],
        };
    }

    public static function qtyText(float $q): string
    {
        return rtrim(rtrim(number_format($q, 1, ',', ''), '0'), ',');
    }
}
