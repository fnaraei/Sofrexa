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
        $o['discounts'] = Db::rows('SELECT d.*, u.name AS user_name FROM order_discounts d LEFT JOIN users u ON u.id = d.user_id WHERE d.order_id = ? ORDER BY d.at, d.rowid', [$id]);
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
        // a guest's QR order waiting for approval is not the table's bill (it opens the approval sheet)
        $id = Db::value("SELECT id FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 AND NOT (channel = 'qr' AND status = 'pending')
            ORDER BY parent_id IS NOT NULL, opened_at LIMIT 1", [$tableId]);
        return $id ? self::get($id) : null;
    }

    /** All open orders with totals and line counts (till list, table map); $statuses and $closedSince for the delivery board. */
    public static function open(array $channels = [], array $statuses = self::OPEN, int $closedSince = 0): array
    {
        $where = 'o.status IN (' . Db::in($statuses) . ') AND o.deleted = 0' . ($closedSince ? ' AND o.closed_at >= ' . $closedSince : '');
        $p = $statuses;
        if ($channels) {
            $where .= ' AND o.channel IN (' . Db::in($channels) . ')';
            $p = [...$p, ...$channels];
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
            // whoever takes an order owns it; a guest order has no waiter until the till shares it out (Assign)
            'waiter_id' => $waiter = $attrs['waiter_id'] ?? ($u['id'] ?? null),
            'assigned_at' => $waiter ? Clock::ms() : null,
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
        $open = Db::value("SELECT id FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 AND NOT (channel = 'qr' AND status = 'pending') ORDER BY opened_at LIMIT 1", [$tableId]);
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
        self::notLeft($o);
        $item = Menu::get($itemId);
        if (!$item['orderable']) {
            throw new \InvalidArgumentException(I18n::t('order.err_soldout', ['name' => tn($item['names'])]));
        }
        $qty = max(0.5, min(99, $qty));
        // the daily stock counts what was sent and what is waiting unsent in any bill (it is sent later)
        if ($item['left'] !== null && $qty > ($left = Menu::left($item['left'] - Menu::reserved($itemId))) + 0.0001) {
            throw new \InvalidArgumentException(I18n::t('order.err_stock', ['name' => tn($item['names']), 'n' => I18n::numAuto($left)]));
        }
        [$modRows, $modsPrice] = self::mods($itemId, $mods);
        $note = mb_substr(trim($note), 0, 200);
        // a promotion running now for this channel lowers the dish price (options keep theirs)
        $promo = \Sofrexa\Modules\Menu\Promotions::best($item, \Sofrexa\Modules\Menu\Promotions::channelOf((string) $o['channel']));
        $unit = $promo ? \Sofrexa\Modules\Menu\Promotions::price((int) $item['price'], (float) $promo['pct']) : (int) $item['price'];
        $same = Db::value("SELECT id FROM order_items WHERE order_id = ? AND item_id = ? AND status = 'new' AND deleted = 0 AND mods = ? AND COALESCE(note, '') = ? AND unit_price = ?",
            [$orderId, $itemId, json_encode($modRows, JSON_UNESCAPED_UNICODE), $note, $unit]);
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
            'unit_price' => $unit,
            'promo_id' => $promo['id'] ?? null,
            'list_price' => $promo ? (int) $item['price'] : null,
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

    /**
     * Chosen options as [{id, name, price}] and their total price. The same rules for the waiter, the till, QR and online:
     * every option must belong to the dish, and each of its option groups needs between min_sel and max_sel choices.
     */
    public static function mods(string $itemId, array $mods): array
    {
        $mods = array_values(array_unique(array_filter(array_map('strval', $mods))));
        $rows = $mods ? Db::rows('SELECT m.id, m.names, m.price, m.group_id FROM modifiers m JOIN item_modifier_groups g ON g.group_id = m.group_id AND g.item_id = ? AND g.deleted = 0
            WHERE m.id IN (' . Db::in($mods) . ') AND m.deleted = 0', [$itemId, ...$mods]) : [];
        if (count($rows) !== count($mods)) {
            throw new ValidationError(['mods' => I18n::t('order.err_option')]);
        }
        $per = array_count_values(array_column($rows, 'group_id'));
        foreach (Db::rows('SELECT g.id, g.names, g.min_sel, g.max_sel FROM item_modifier_groups l JOIN modifier_groups g ON g.id = l.group_id AND g.deleted = 0
            WHERE l.item_id = ? AND l.deleted = 0', [$itemId]) as $g) {
            $n = $per[$g['id']] ?? 0;
            if ($n < (int) $g['min_sel'] || ((int) $g['max_sel'] > 0 && $n > (int) $g['max_sel'])) {
                throw new ValidationError(['mods' => I18n::t('order.err_required', ['name' => tn($g['names'])])]);
            }
        }
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

    /**
     * Sends every unsent line to the kitchen/bar (tickets per station). Returns the number of lines sent.
     * $lineIds: only these lines (a guest's QR order joining a bill that has the waiter's unsent lines).
     */
    public static function send(string $orderId, ?array $lineIds = null): int
    {
        $o = self::editable($orderId);
        $new = Db::rows("SELECT * FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId]);
        if ($lineIds !== null) {
            $new = array_values(array_filter($new, static fn(array $l): bool => in_array($l['id'], $lineIds, true)));
        }
        if (!$new) {
            return 0;
        }
        $now = Clock::ms();
        // one step: the lines, their tickets and the stock they take go together or not at all
        return Db::tx(static function () use ($new, $now, $o, $orderId): int {
            $ids = array_column($new, 'id');
            // read again inside the lock: a second tap (or a second device) must not send the same lines twice
            $new = Db::rows("SELECT * FROM order_items WHERE id IN (" . Db::in($ids) . ") AND status = 'new' AND deleted = 0", $ids);
            if (!$new) {
                return 0;
            }
            self::checkDaily($new);
            $round = (int) Db::value("SELECT COALESCE(MAX(round), 0) + 1 FROM order_items WHERE order_id = ? AND status NOT IN ('new') AND deleted = 0", [$orderId]);
            foreach ($new as $l) {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'sent', 'sent_at' => $now, 'round' => $round, 'sent_qty' => (float) $l['qty']]);
            }
            if ($o['status'] === 'billed') {
                Db::save('orders', ['id' => $o['id'], 'status' => 'open']);
            }
            Tickets::kitchen($orderId, array_column($new, 'id'), $round);
            \Sofrexa\Modules\Stock\Stock::consume(array_column($new, 'id'));
            return count($new);
        });
    }

    /** Lines about to go to the kitchen must fit in today's daily stock (the last check: bills may have reserved the same portions). */
    private static function checkDaily(array $lines): void
    {
        $want = [];
        foreach ($lines as $l) {
            if ($l['item_id']) {
                $want[$l['item_id']] = ($want[$l['item_id']] ?? 0) + (float) $l['qty'];
            }
        }
        if (!$want) {
            return;
        }
        $ids = array_keys($want);
        $capped = Db::rows('SELECT id, names, daily_stock FROM items WHERE daily_stock IS NOT NULL AND id IN (' . Db::in($ids) . ')', $ids);
        if (!$capped) {
            return;
        }
        $sold = Menu::soldToday();
        foreach ($capped as $i) {
            $left = Menu::left((float) $i['daily_stock'] - (float) ($sold[$i['id']] ?? 0));
            if ($want[$i['id']] > $left + 0.0001) {
                throw new \InvalidArgumentException(I18n::t('order.err_stock', ['name' => tn($i['names']), 'n' => I18n::numAuto($left)]));
            }
        }
    }

    /**
     * Void a line. Sent lines need a reason and orders.void; the station gets a cancel ticket. What happens to the dish
     * depends on how far the kitchen got: marked ready (or served) → it is cooked, booked as waste at once; only sent →
     * the till asks the kitchen and decides (back to stock or waste, see settleVoid). A line voided once cannot be voided again.
     */
    public static function voidLine(string $lineId, string $reason, float $qty = 0): void
    {
        $l = self::line($lineId);
        $o = self::editable($l['order_id']);
        if ($l['status'] === 'void') {
            throw new \InvalidArgumentException(I18n::t('order.err_voided'));
        }
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
        $u = Auth::user();
        [$voidId, $qty, $stock] = Db::tx(static function () use ($lineId, $qty, $reason, $u): array {
            // read again inside the lock: two taps at once must not both void (and give the stock back twice)
            $l = Db::row('SELECT * FROM order_items WHERE id = ? AND deleted = 0', [$lineId]);
            if (!$l || $l['status'] === 'void' || $l['status'] === 'new') {
                throw new \InvalidArgumentException(I18n::t('order.err_voided'));
            }
            $qty = $qty > 0 && $qty < (float) $l['qty'] ? $qty : (float) $l['qty'];
            // a drink sent to the bar with no recipe behind it has nothing to ask the kitchen about
            $cooks = $l['station'] === 'kitchen' || Db::value("SELECT 1 FROM stock_moves WHERE order_item_id = ? AND reason = 'sale' LIMIT 1", [$l['id']]);
            $stock = in_array($l['status'], ['ready', 'served'], true) ? 'waste' : ($cooks ? 'pending' : null);
            $mark = ['status' => 'void', 'void_reason' => $reason, 'void_by' => $u['id'] ?? null, 'void_at' => Clock::ms(), 'void_stock' => $stock];
            if ($qty < (float) $l['qty']) {
                // partial void: the rest stays as it is, the voided part becomes its own line
                Db::save('order_items', ['id' => $l['id'], 'qty' => (float) $l['qty'] - $qty]);
                $void = $l;
                unset($void['id']);
                $id = Db::save('order_items', ['qty' => $qty, 'mods' => json_arr($l['mods']), 'void_of' => $l['id']] + $mark + $void);
            } else {
                $id = Db::save('order_items', ['id' => $l['id']] + $mark);
            }
            if ($stock === 'waste') {
                \Sofrexa\Modules\Stock\Stock::settleVoid($id, 'waste');
            }
            return [$id, $qty, $stock];
        });
        self::recalc($o['id']);
        $amount = (int) round($qty * ((int) $l['unit_price'] + (int) $l['mods_price']));
        Audit::log('order.void_item', self::where($o) . ' · ' . $l['name'] . ' ×' . self::qtyText($qty) . ' · ' . Money::fmt($amount, false, 'tr') . ' · sebep: ' . $reason
            . ($stock === 'waste' ? ' · hazırdı: zayi' : ''), 'order', $o['id'], ['line' => $l['id']]);
        Tickets::void($o['id'], $l['id'], $qty, $reason);
        if ($l['status'] === 'ready') {
            \Sofrexa\Modules\Kitchen\Kitchen::retell($o['id']); // a cancelled plate is not carried out
        }
        if ($stock === 'pending') {
            self::askKitchen($o, $voidId);
        }
    }

    /** "Masa 2 · 1× Margarita iptal — mutfağa sorun": the till decides whether the cancelled dish was already cooked. */
    private static function askKitchen(array $o, string $voidId): void
    {
        $v = Db::row('SELECT name, qty, void_by FROM order_items WHERE id = ?', [$voidId]);
        Notify::pushLine('void', ['where' => self::where($o), 'what' => self::qtyText((float) $v['qty']) . '× ' . $v['name'],
            'by' => (string) Db::value('SELECT name FROM users WHERE id = ?', [$v['void_by']])], 'cashier', $voidId);
    }

    /**
     * The till's answer for a cancelled dish that was only sent: 'returned' — not cooked, the ingredients go back to stock;
     * 'waste' — it was cooked, the ingredients are booked as waste (it can still go to another bill or a staff member).
     */
    public static function settleVoid(string $voidId, string $how): void
    {
        if (!Auth::can('cash.pay') && !Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $how = $how === 'returned' ? 'returned' : 'waste';
        $v = Db::tx(static function () use ($voidId, $how): array {
            $v = Db::row("SELECT * FROM order_items WHERE id = ? AND status = 'void' AND deleted = 0", [$voidId]) ?? throw new HttpError(404);
            if ($v['void_stock'] !== 'pending') {
                throw new \InvalidArgumentException(I18n::t('void.err_done'));
            }
            Db::save('order_items', ['id' => $voidId, 'void_stock' => $how, 'reuse_at' => Clock::ms(), 'reuse_by' => Auth::user()['id'] ?? null]);
            \Sofrexa\Modules\Stock\Stock::settleVoid($voidId, $how === 'returned' ? 'return' : 'waste');
            return $v;
        });
        Notify::closeLine($voidId);
        $o = Db::row('SELECT o.*, t.number AS table_no FROM orders o LEFT JOIN tables t ON t.id = o.table_id WHERE o.id = ?', [$v['order_id']]);
        Audit::log('order.void_stock', self::where($o) . ' · ' . $v['name'] . ' ×' . self::qtyText((float) $v['qty']) . ' · ' . ($how === 'returned' ? 'stoka döndü' : 'zayi'), 'order', $v['order_id'], ['line' => $voidId]);
    }

    /**
     * A cooked dish that was cancelled goes to another open bill after all (the same dish was ordered there): a ready line
     * on that bill at the price the dish was made for, no new kitchen ticket, and its ingredients count as that sale.
     */
    public static function reuseVoided(string $voidId, string $orderId): string
    {
        if (!Auth::can('cash.pay') && !Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $target = self::editable($orderId);
        self::notLeft($target);
        $lineId = Db::tx(static function () use ($voidId, $target): string {
            $v = self::voidedDish($voidId);
            $now = Clock::ms();
            $id = Db::save('order_items', ['order_id' => $target['id'], 'item_id' => $v['item_id'], 'name' => $v['name'], 'qty' => (float) $v['qty'],
                'unit_price' => (int) $v['unit_price'], 'promo_id' => $v['promo_id'], 'list_price' => $v['list_price'], 'mods' => json_arr($v['mods']), 'mods_price' => (int) $v['mods_price'],
                'note' => $v['note'], 'station' => $v['station'], 'status' => 'ready', 'vat_rate' => (float) $v['vat_rate'], 'cost' => (int) $v['cost'],
                'round' => (int) Db::value("SELECT COALESCE(MAX(round), 0) + 1 FROM order_items WHERE order_id = ? AND status <> 'new'", [$target['id']]),
                'sent_at' => $now, 'ready_at' => $now, 'sent_qty' => (float) $v['qty'], 'created_by' => Auth::user()['id'] ?? null, 'created_at' => $now]);
            Db::save('order_items', ['id' => $voidId, 'void_stock' => 'table', 'reuse_ref' => $id, 'reuse_at' => $now, 'reuse_by' => Auth::user()['id'] ?? null]);
            \Sofrexa\Modules\Stock\Stock::reuseWaste($voidId, $id);
            return $id;
        });
        self::recalc($target['id']);
        Notify::closeLine($voidId);
        // the dish is waiting at the pass: the waiter of that bill hears "ready" like for any plate
        $n = self::line($lineId);
        \Sofrexa\Modules\Kitchen\Kitchen::callAgain($target['id'], (int) $n['round'], (string) $n['station']);
        $v = self::line($voidId);
        Audit::log('order.void_reuse', $v['name'] . ' ×' . self::qtyText((float) $v['qty']) . ' → ' . self::where($target), 'order', $target['id'], ['line' => $lineId, 'from' => $voidId]);
        return $lineId;
    }

    /** A cooked dish that was cancelled is taken by a staff member: its price is charged to them (deducted from their pay). */
    public static function chargeVoidedToStaff(string $voidId, string $userId): string
    {
        if (!Auth::can('cash.pay') && !Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $name = Db::value('SELECT name FROM users WHERE id = ? AND deleted = 0', [$userId]) ?? throw new HttpError(404);
        [$pid, $v, $amount] = Db::tx(static function () use ($voidId, $userId, $name): array {
            $v = self::voidedDish($voidId);
            $amount = (int) round((float) $v['qty'] * ((int) $v['unit_price'] + (int) $v['mods_price']));
            $now = Clock::ms();
            $pid = Db::append('payroll', ['user_id' => $userId, 'period' => date('Y-m', intdiv($now, 1000)), 'kind' => 'charge', 'total' => $amount, 'method' => 'deduction',
                'note' => mb_substr(self::qtyText((float) $v['qty']) . '× ' . $v['name'] . ' (iptal)', 0, 200), 'at' => $now, 'user_by' => Auth::user()['id'] ?? null]);
            Db::save('order_items', ['id' => $voidId, 'void_stock' => 'staff', 'reuse_ref' => $userId, 'reuse_at' => $now, 'reuse_by' => Auth::user()['id'] ?? null]);
            return [$pid, $v, $amount];
        });
        Notify::closeLine($voidId);
        Audit::log('order.void_staff', $v['name'] . ' ×' . self::qtyText((float) $v['qty']) . ' → ' . $name . ' · ' . Money::fmt($amount, false, 'tr'), 'user', $userId, ['line' => $voidId, 'payroll' => $pid]);
        return $pid;
    }

    /** A cancelled dish that is (or may be) cooked and not given anywhere yet; a pending one is booked as waste first. */
    private static function voidedDish(string $voidId): array
    {
        $v = Db::row("SELECT * FROM order_items WHERE id = ? AND status = 'void' AND deleted = 0", [$voidId]) ?? throw new HttpError(404);
        if ($v['void_stock'] === 'pending') {
            Db::save('order_items', ['id' => $voidId, 'void_stock' => 'waste']);
            \Sofrexa\Modules\Stock\Stock::settleVoid($voidId, 'waste');
            $v['void_stock'] = 'waste';
        }
        if ($v['void_stock'] !== 'waste') {
            throw new \InvalidArgumentException(I18n::t('void.err_done'));
        }
        return $v;
    }

    public static function discount(string $orderId, string $kind, float $value, string $reason = ''): void
    {
        if (!Auth::can('orders.discount')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $kind = $kind === 'amount' ? 'amount' : 'pct';
        if ($value <= 0 || ($kind === 'pct' && $value > 100)) {
            throw new ValidationError(['value' => I18n::t('order.err_discount')]);
        }
        // the bill read inside the write lock: a payment taken at the same moment is seen
        [$o, $amount] = Db::tx(static function () use ($orderId, $kind, $value, $reason): array {
            $o = self::editable($orderId);
            $amount = $kind === 'pct' ? (int) round((int) $o['subtotal'] * $value / 100) : (int) round($value);
            $amount = min($amount, max(0, (int) $o['subtotal'] - (int) $o['discount']));
            // money already taken is not given back by a discount: the bill may not drop below what was paid (a dish cancelled
            // after a part payment is settled by an explicit refund on the payment screen instead)
            if ((int) $o['paid'] > 0 && (int) $o['total'] - $amount < (int) $o['paid']) {
                throw new ValidationError(['value' => I18n::t('order.err_discount_paid', ['paid' => Money::fmt((int) $o['paid'])])]);
            }
            Db::append('order_discounts', ['order_id' => $orderId, 'kind' => $kind, 'value' => $value, 'amount' => $amount, 'reason' => mb_substr($reason, 0, 120) ?: null, 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
            self::recalc($orderId);
            return [$o, $amount];
        });
        Audit::log('order.discount', self::where($o) . ' · ' . ($kind === 'pct' ? '%' . I18n::num($value, 0, 'tr') : Money::fmt((int) $value, false, 'tr')) . ' · ' . Money::fmt($amount, false, 'tr') . ($reason !== '' ? ' · ' . $reason : ''), 'order', $orderId);
    }

    /** Removes all discounts of an open order (a reverse entry keeps the history). */
    public static function clearDiscount(string $orderId): void
    {
        $o = self::editable($orderId);
        if (!\Sofrexa\Modules\Customers\Loyalty::activeDiscounts($orderId)) {
            return;
        }
        // every entry in force is cancelled, not only the part the bill could use (a ₺150 discount on a ₺100 bill)
        Db::tx(static function () use ($orderId): void {
            self::replaceDiscounts($orderId, [], 'iptal');
            \Sofrexa\Modules\Customers\Loyalty::refund($orderId);
            self::recalc($orderId);
        });
        Audit::log('order.discount', self::where($o) . ' · indirim kaldırıldı', 'order', $orderId);
    }

    // ------------------------------------------------------------ tables

    /**
     * Moves a table's bill to another (free) table — together with every open bill of its family, in one step, so a
     * family of bills never ends up on two tables: the first bill of the table and every bill split off it, however
     * many times over (a bill split off a split bill too), whichever of them the move started from.
     */
    public static function moveTable(string $orderId, string $tableId): void
    {
        $o = self::editable($orderId);
        if (!in_array($o['channel'], ['table', 'qr'], true)) {
            throw new \InvalidArgumentException(I18n::t('order.err_not_table')); // a take-away or delivery has no table to leave
        }
        $to = Db::value('SELECT number FROM tables WHERE id = ? AND deleted = 0', [$tableId]);
        if ($to === null) {
            throw new HttpError(404);
        }
        $family = array_values(array_unique([...self::family($orderId), $orderId]));
        Db::tx(static function () use ($family, $tableId): void {
            if (Db::value("SELECT 1 FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0 AND id NOT IN (" . Db::in($family) . ')', [$tableId, ...$family])) {
                throw new \InvalidArgumentException(I18n::t('order.err_table_busy'));
            }
            foreach ($family as $id) {
                Db::save('orders', ['id' => $id, 'table_id' => $tableId]);
            }
        });
        Audit::log('order.transfer', self::where($o) . ' → Masa ' . $to . (count($family) > 1 ? ' · ' . count($family) . ' hesap' : ''), 'order', $orderId);
    }

    /** The open bills of an order's family: up to the first bill it was split from, then down every split, at any depth. */
    public static function family(string $orderId): array
    {
        $root = $orderId;
        for ($i = 0; $i < 50 && ($up = Db::value('SELECT parent_id FROM orders WHERE id = ?', [$root])); $i++) {
            $root = (string) $up;
        }
        return array_column(Db::rows("WITH RECURSIVE fam(id) AS (SELECT ? UNION SELECT o.id FROM orders o JOIN fam ON o.parent_id = fam.id)
            SELECT o.id FROM orders o JOIN fam ON fam.id = o.id WHERE o.status IN ('pending', 'open', 'billed') AND o.deleted = 0", [$root]), 'id');
    }

    /**
     * Moves every line of $fromId into $intoId and closes $fromId. The merged bill owes exactly what the two did
     * (decision 44): the discounts of both become the amounts they gave on their own bill right then — 10% of the
     * target stays 10% of the target's dishes, and a ₺150 discount that could only take ₺100 off its bill brings ₺100.
     * Points spent on the merged-in bill go with its discount, so taking that discount off gives them back, once.
     */
    public static function merge(string $fromId, string $intoId): void
    {
        $from = self::editable($fromId);
        $into = self::editable($intoId);
        self::notLeft($from);
        self::notLeft($into);
        Db::tx(static function () use ($fromId, $intoId, &$from, &$into): void {
            // both bills read again inside the lock: a payment taken on the merged-away bill at the same moment is seen
            $from = self::editable($fromId);
            $into = self::editable($intoId);
            if ((int) $from['paid'] > 0) {
                throw new \InvalidArgumentException(I18n::t('order.err_has_payments'));
            }
            // points used beyond what a bill can take off (it shrank since) go back now, while their value is still
            // known: once frozen to an amount below, nothing would tell the rest was never used
            \Sofrexa\Modules\Customers\Loyalty::settle($fromId);
            \Sofrexa\Modules\Customers\Loyalty::settle($intoId);
            $entries = [];
            foreach ([$intoId => $into, $fromId => $from] as $id => $bill) {
                foreach (self::effective(\Sofrexa\Modules\Customers\Loyalty::activeDiscounts($id), (int) $bill['subtotal']) as $d) {
                    if ($d['amount'] > 0) {
                        $entries[] = self::frozen($d, $d['amount'], $id === $fromId && $d['reason'] !== 'puan' ? 'birleştirme: ' . ($d['reason'] ?? '') : null);
                    }
                }
            }
            if ($entries || \Sofrexa\Modules\Customers\Loyalty::activeDiscounts($intoId)) {
                self::replaceDiscounts($intoId, $entries, 'birleştirme');
            }
            \Sofrexa\Modules\Customers\Loyalty::moveRedemptions($fromId, $intoId);
            foreach (Db::rows('SELECT id FROM order_items WHERE order_id = ? AND deleted = 0', [$fromId]) as $l) {
                Db::save('order_items', ['id' => $l['id'], 'order_id' => $intoId]);
            }
            Db::save('orders', ['id' => $fromId, 'status' => 'void', 'closed_at' => Clock::ms(), 'note' => 'merged:' . $intoId]);
            self::recalc($fromId);
        });
        self::recalc($intoId);
        // plates waiting at the pass moved with their lines: their alert moves to the bill they are on now
        self::ended($fromId);
        \Sofrexa\Modules\Kitchen\Kitchen::retell($intoId);
        Audit::log('order.merge', self::where($from) . ' → ' . self::where($into), 'order', $intoId);
    }

    /**
     * The discounts in force on a bill as the amounts they take off it now, together never more than the bill (a ₺150
     * discount on a ₺100 bill takes ₺100). Points come first: they are already spent. Each entry gets 'amount' = that.
     */
    private static function effective(array $entries, int $sub): array
    {
        $points = array_values(array_filter($entries, static fn(array $d): bool => $d['reason'] === 'puan'));
        $rest = array_values(array_filter($entries, static fn(array $d): bool => $d['reason'] !== 'puan'));
        $room = max(0, $sub);
        $out = [];
        foreach ([...$points, ...$rest] as $d) {
            $raw = $d['kind'] === 'pct' ? (int) round($sub * (float) $d['value'] / 100) : (int) $d['amount'];
            $amount = max(0, min($raw, $room));
            $room -= $amount;
            $out[] = ['amount' => $amount] + $d;
        }
        return $out;
    }

    /** A discount entry fixed at an amount (its reason kept, or $reason), for replaceDiscounts or a new bill. */
    private static function frozen(array $d, int $amount, ?string $reason = null): array
    {
        return ['kind' => 'amount', 'value' => $amount, 'amount' => $amount, 'reason' => $reason ?? $d['reason'], 'user_id' => $d['user_id'] ?? null];
    }

    public static function setWaiter(string $orderId, string $userId): void
    {
        $o = self::editable($orderId);
        $name = Db::value('SELECT name FROM users WHERE id = ? AND active = 1 AND deleted = 0', [$userId]);
        if ($name === null) {
            throw new HttpError(404);
        }
        Db::save('orders', ['id' => $orderId, 'waiter_id' => $userId, 'assigned_at' => Clock::ms()]);
        Notify::follow($orderId, $userId);
        Audit::log('order.transfer', self::where($o) . ' · garson → ' . $name, 'order', $orderId);
    }

    /** Moves the chosen lines to a separate bill of the same table (pay part of the table). Returns the new order id. */
    public static function split(string $orderId, array $lineIds): string
    {
        $o = self::editable($orderId);
        self::notLeft($o);
        $lineIds = array_values(array_filter(array_map('strval', $lineIds)));
        $lines = $lineIds ? Db::rows('SELECT id FROM order_items WHERE order_id = ? AND id IN (' . Db::in($lineIds) . ") AND deleted = 0 AND status <> 'void'", [$orderId, ...$lineIds]) : [];
        if (!$lines) {
            throw new ValidationError(['lines' => I18n::t('order.err_pick_lines')]);
        }
        $new = Db::tx(static function () use ($orderId, $lines): string {
            $o = self::editable($orderId); // what was paid, read inside the lock
            $ids = array_column($lines, 'id');
            $sub = (int) Db::value("SELECT COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void'", [$orderId]);
            $moved = (int) Db::value('SELECT COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) FROM order_items WHERE id IN (' . Db::in($ids) . ')', $ids);
            $rest = $sub - $moved;
            \Sofrexa\Modules\Customers\Loyalty::settle($orderId); // as in merge: before the discounts are frozen
            [$mine, $theirs, $points] = self::shareDiscounts(\Sofrexa\Modules\Customers\Loyalty::activeDiscounts($orderId), $sub, $moved);
            // what was already paid (money or points) must still be covered by what stays on this bill
            $restTotal = $rest - min($rest, self::discountTotal($mine, $rest));
            if ((int) $o['paid'] > $restTotal || $points > $rest) {
                throw new \InvalidArgumentException(I18n::t('order.err_split_paid'));
            }
            $new = self::create($o['channel'], ['table_id' => $o['table_id'], 'customer_id' => $o['customer_id'], 'waiter_id' => $o['waiter_id'], 'parent_id' => $orderId, 'label' => $o['label']]);
            foreach ($ids as $id) {
                Db::save('order_items', ['id' => $id, 'order_id' => $new]);
            }
            if ($mine || $theirs) {
                self::replaceDiscounts($orderId, $mine, 'bölme');
            }
            foreach ($theirs as $d) {
                Db::append('order_discounts', ['order_id' => $new, 'kind' => $d['kind'], 'value' => $d['value'], 'amount' => $d['amount'], 'reason' => $d['reason'], 'user_id' => $d['user_id'], 'at' => Clock::ms()]);
            }
            self::recalc($orderId);
            self::recalc($new);
            return $new;
        });
        \Sofrexa\Modules\Kitchen\Kitchen::retell($orderId);
        \Sofrexa\Modules\Kitchen\Kitchen::retell($new);
        Audit::log('order.split', self::where($o) . ' · ' . count($lines) . ' ürün ayrı hesaba', 'order', $new);
        return $new;
    }

    /**
     * The discounts of a bill when part of it ($moved of $sub, kuruş) leaves for a new bill (decision 44). Each discount
     * is shared as the amount it takes off now, in proportion to the dishes; the kuruş left over by rounding go to the
     * largest fractions, so the two bills together owe exactly what the one did (two ₺0,01 dishes at 50% stay ₺0,01).
     * Points stay on this bill — they were used for it; if they leave it too little room, the rest of the discount
     * goes with the dishes that left. Returns [this bill's entries, the new bill's entries, points].
     */
    private static function shareDiscounts(array $active, int $sub, int $moved): array
    {
        $entries = self::effective($active, $sub);
        $points = 0;
        $share = [];
        foreach ($entries as $k => $d) {
            if ($d['reason'] === 'puan') {
                $points += $d['amount'];
            } else {
                $share[$k] = $sub > 0 ? $d['amount'] * $moved / $sub : 0.0;
            }
        }
        $give = array_map(static fn(float $s): int => (int) floor($s), $share);
        $left = (int) round(array_sum($share)) - array_sum($give);
        $order = array_keys($share);
        usort($order, static fn(int $a, int $b): int => [$share[$b] - floor($share[$b]), $a] <=> [$share[$a] - floor($share[$a]), $b]);
        foreach ($order as $k) {
            if ($left <= 0) {
                break;
            }
            $give[$k]++;
            $left--;
        }
        // what stays here may not exceed what stays here: move the excess over with the dishes that left
        $over = $points + array_sum(array_map(static fn(int $k): int => $entries[$k]['amount'] - $give[$k], array_keys($give))) - ($sub - $moved);
        foreach (array_keys($give) as $k) {
            if ($over <= 0) {
                break;
            }
            $room = min($entries[$k]['amount'] - $give[$k], $moved - array_sum($give));
            $take = max(0, min($over, $room));
            $give[$k] += $take;
            $over -= $take;
        }
        $mine = [];
        $theirs = [];
        foreach ($entries as $k => $d) {
            $there = $give[$k] ?? 0;
            if ($d['amount'] - $there > 0) {
                $mine[] = self::frozen($d, $d['amount'] - $there);
            }
            if ($there > 0) {
                $theirs[] = self::frozen($d, $there);
            }
        }
        return [$mine, $theirs, $points];
    }

    /** Starts the discounts of an order again from $entries (the table is append-only: a reset entry, then the new ones). */
    private static function replaceDiscounts(string $orderId, array $entries, string $why): void
    {
        $sum = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM order_discounts WHERE order_id = ?', [$orderId]);
        $uid = Auth::user()['id'] ?? null;
        Db::append('order_discounts', ['order_id' => $orderId, 'kind' => 'reverse', 'value' => 0, 'amount' => -$sum, 'reason' => $why, 'user_id' => $uid, 'at' => Clock::ms()]);
        foreach ($entries as $d) {
            if ($d['kind'] === 'pct' || (int) $d['amount'] > 0) {
                Db::append('order_discounts', ['order_id' => $orderId, 'kind' => $d['kind'], 'value' => $d['value'], 'amount' => $d['amount'], 'reason' => $d['reason'], 'user_id' => $d['user_id'] ?? $uid, 'at' => Clock::ms()]);
            }
        }
    }

    /** The discount of active entries on a subtotal: a percentage is always of the bill as it is now (kuruş). */
    public static function discountTotal(array $entries, int $sub): int
    {
        $sum = 0;
        foreach ($entries as $d) {
            $sum += $d['kind'] === 'pct' ? (int) round($sub * (float) $d['value'] / 100) : (int) $d['amount'];
        }
        return max(0, min($sum, $sub));
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
     * $limit: pay only this much now (one guest's share); cash above it becomes that guest's change.
     */
    public static function pay(string $orderId, array $parts, ?string $customerId = null, bool $receipt = true, ?int $limit = null): array
    {
        $o = self::editable($orderId);
        if (!array_filter($o['lines'], static fn(array $l): bool => $l['status'] !== 'void')) {
            throw new \InvalidArgumentException(I18n::t('order.err_empty'));
        }
        \Sofrexa\Core\Router::tillOnly(); // taking money is the till's work, from whatever page
        // cash and card go into a shift (the drawer, the card machine); a bill put on account moves no money
        $shift = array_filter($parts, static fn(array $p): bool => in_array($p['method'] ?? 'cash', ['cash', 'card'], true)) ? Shifts::forCash() : Shifts::currentId();
        if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId])) {
            self::send($orderId);
        }
        $rates = Rates::latest();
        $u = Auth::user();
        // one step, read inside the lock: two tills taking the last payment of a bill at once cannot both take it
        return Db::tx(static function () use ($orderId, $parts, $customerId, $receipt, $limit, $shift, $rates, $u): array {
            $o = self::get($orderId);
            if (!in_array($o['status'], self::OPEN, true)) {
                throw new \InvalidArgumentException(I18n::t('order.err_closed'));
            }
            $over = (int) $o['paid'] - (int) $o['total'];
            if ($over > 0) {
                // more was paid than the bill now comes to (a dish cancelled after a part payment): the difference is
                // given back first, on purpose and on record (refund), not swallowed by closing the bill
                throw new \InvalidArgumentException(I18n::t('pay.err_overpaid', ['amount' => Money::fmt($over)]));
            }
            if ($over === 0) {
                // nothing left to pay (discounted to ₺0, or covered by points): the bill closes without a made-up payment
                self::close($orderId);
                if ($receipt) {
                    Tickets::receipt($orderId);
                }
                Audit::log('order.free', self::where($o) . ' · ' . Money::fmt((int) $o['subtotal'], false, 'tr') . ' · ₺0 ile kapatıldı', 'order', $orderId);
                return ['change' => 0, 'paid' => true, 'due' => 0, 'free' => true];
            }
            return self::takePayment($o, $parts, $customerId, $receipt, $limit, $shift, $rates, $u);
        });
    }

    /**
     * The bill now comes to less than was already paid (a dish cancelled after part of it was paid): the difference goes
     * back and the bill closes (decision 46). What was put on the customer's account comes off that account first — it
     * was never money in the drawer (decision 47) — and only the rest is paid out, from the drawer ('cash') or reversed
     * on the card machine ('card', never more than was taken on the card). Each part is a payment of minus that much in
     * the shift that makes it. A dish added and not sent yet goes to the kitchen first, as when a payment closes a bill.
     * Returns the amount taken off the bill's payments.
     */
    public static function refund(string $orderId, string $method, bool $receipt = true): int
    {
        if (!Auth::can('cash.pay')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $method = $method === 'card' ? 'card' : 'cash';
        $shift = Shifts::forCash();
        if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId])) {
            self::send($orderId);
        }
        $u = Auth::user();
        [$o, $plan] = Db::tx(static function () use ($orderId, $method, $shift, $u): array {
            $o = self::get($orderId);
            if (!in_array($o['status'], self::OPEN, true)) {
                throw new \InvalidArgumentException(I18n::t('order.err_closed'));
            }
            $plan = self::refundPlan($o);
            if ($plan['over'] <= 0) {
                throw new \InvalidArgumentException(I18n::t('pay.err_no_refund'));
            }
            if ($plan['money'] > 0 && $method === 'card' && $plan['money'] > $plan['card']) {
                throw new \InvalidArgumentException(I18n::t('pay.err_card_refund', ['amount' => Money::fmt($plan['card'])]));
            }
            $row = ['order_id' => $orderId, 'shift_id' => $shift, 'currency' => 'TRY', 'amount_fx' => 0, 'rate' => 1, 'change_given' => 0,
                'at' => Clock::ms(), 'user_id' => $u['id'] ?? null, 'courier_id' => null];
            foreach ($plan['account'] as $customerId => $amount) {
                Db::append('payments', ['method' => 'account', 'amount' => -$amount, 'customer_id' => (string) $customerId] + $row);
                Accounts::refund((string) $customerId, $amount, $orderId, self::where($o) . ' · fazla ödeme iadesi');
            }
            if ($plan['money'] > 0) {
                Db::append('payments', ['method' => $method, 'amount' => -$plan['money'], 'customer_id' => $o['customer_id']] + $row);
            }
            self::recalc($orderId);
            self::close($orderId);
            return [$o, $plan];
        });
        if ($plan['money'] > 0 && $method === 'cash') {
            Tickets::drawer();
        }
        if ($receipt) {
            Tickets::receipt($orderId);
        }
        $account = array_sum($plan['account']);
        Audit::log('order.refund', self::where($o) . ' · ' . Money::fmt($plan['over'], false, 'tr') . ' iade'
            . ($plan['money'] > 0 ? ' · ' . Money::fmt($plan['money'], false, 'tr') . ' ' . ($method === 'card' ? 'kart' : 'nakit') : '')
            . ($account > 0 ? ' · ' . Money::fmt($account, false, 'tr') . ' cari borçtan düşüldü' : ''), 'order', $orderId);
        return $plan['over'];
    }

    /**
     * How an overpaid bill's difference goes back: 'account' — per customer, the part that comes off what was put on
     * their account (the newest account payments first); 'money' — the rest, paid out in cash or on the card; 'card' —
     * how much was taken on the card, the most a card reversal may give back.
     * @return array{over:int, account:array<string,int>, money:int, card:int}
     */
    public static function refundPlan(array $o): array
    {
        $over = max(0, (int) $o['paid'] - (int) $o['total']);
        // each customer's net account payment on this bill, the customer of the newest payment first. "Newest" is the
        // time, then the id — time-ordered, the same on the PC and the web copy — so two payments in the same
        // millisecond still have one order, the order they were taken in
        $net = [];
        foreach (Db::rows("SELECT customer_id, amount FROM payments WHERE order_id = ? AND method = 'account' ORDER BY at DESC, id DESC", [$o['id']]) as $r) {
            $net[(string) $r['customer_id']] = ($net[(string) $r['customer_id']] ?? 0) + (int) $r['amount'];
        }
        $left = $over;
        $account = [];
        foreach ($net as $customerId => $amount) {
            if ($left <= 0) {
                break;
            }
            if ($amount > 0) {
                $take = min($left, $amount);
                $account[$customerId] = $take;
                $left -= $take;
            }
        }
        $card = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND method = 'card'", [$o['id']]);
        return ['over' => $over, 'account' => $account, 'money' => $left, 'card' => max(0, $card)];
    }

    /** The payment itself, inside pay()'s transaction. */
    private static function takePayment(array $o, array $parts, ?string $customerId, bool $receipt, ?int $limit, ?string $shift, array $rates, ?array $u): array
    {
        $orderId = $o['id'];
        $due = max(0, (int) $o['total'] - (int) $o['paid']);
        if ($limit !== null) {
            $due = min($due, max(0, $limit));
        }
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
        // foreign cash of exactly the shown amount (rounded to the cent) may miss the lira total by a few kuruş
        foreach (array_reverse(array_keys($rows)) as $i) {
            if ($sum < $due && $rows[$i]['cur'] !== 'TRY' && $due - $sum <= (int) ceil($rows[$i]['rate'])) {
                $rows[$i]['amount'] += $due - $sum;
                $sum = $due;
            }
        }
        if ($sum > $due) {
            // only cash can be given back; card or account over the due amount is refused
            $nonCash = array_sum(array_map(static fn(array $r): int => $r['method'] === 'cash' ? 0 : $r['amount'], $rows));
            if ($nonCash > $due) {
                throw new ValidationError(['amount' => I18n::t('order.err_over')]);
            }
            $change = $sum - $due;
        }
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

    /**
     * The bill is settled, in the shift that is open now (its sales belong to that shift; each payment to its own).
     * Paying does not serve the food (decision 38): what the kitchen has not finished stays on its screen; dishes already
     * ready at a table that pays are taken to it.
     */
    private static function close(string $orderId): void
    {
        Db::save('orders', ['id' => $orderId, 'status' => 'paid', 'closed_at' => Clock::ms(), 'closed_shift_id' => Shifts::currentId()]);
        if (in_array(Db::value('SELECT channel FROM orders WHERE id = ?', [$orderId]), ['table', 'qr'], true)) {
            foreach (Db::rows("SELECT id FROM order_items WHERE order_id = ? AND status = 'ready' AND deleted = 0", [$orderId]) as $l) {
                Db::save('order_items', ['id' => $l['id'], 'status' => 'served', 'served_at' => Clock::ms()]);
            }
            \Sofrexa\Modules\QrOrder\QrOrders::closeSessions(Db::value('SELECT table_id FROM orders WHERE id = ?', [$orderId]));
        }
        self::ended($orderId); // a table's plates were carried out: no alert left, whoever closed it
        \Sofrexa\Modules\Customers\Loyalty::settle($orderId);
        \Sofrexa\Modules\Customers\Loyalty::earn($orderId);
    }

    /** Cancels a whole open order with a reason (only when nothing was paid). */
    public static function void(string $orderId, string $reason): void
    {
        if (!Auth::can('orders.void')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $o = self::editable($orderId);
        $reason = trim($reason) ?: '—';
        $ask = [];
        Db::tx(static function () use (&$o, $orderId, $reason, &$ask): void {
            $o = self::editable($orderId); // read inside the lock: a bill paid at the same moment is not cancelled
            if ((int) $o['paid'] > 0) {
                throw new \InvalidArgumentException(I18n::t('order.err_has_payments'));
            }
            foreach (Db::rows("SELECT * FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void'", [$orderId]) as $l) {
                // the same rules as for one line: cooked → waste, only sent → the till asks the kitchen, unsent → nothing to do
                $cooks = $l['station'] === 'kitchen' || Db::value("SELECT 1 FROM stock_moves WHERE order_item_id = ? AND reason = 'sale' LIMIT 1", [$l['id']]);
                $stock = $l['status'] === 'new' ? null : (in_array($l['status'], ['ready', 'served'], true) ? 'waste' : ($cooks ? 'pending' : null));
                Db::save('order_items', ['id' => $l['id'], 'status' => 'void', 'void_reason' => $reason, 'void_by' => Auth::user()['id'] ?? null, 'void_at' => Clock::ms(), 'void_stock' => $stock]);
                if ($stock === 'waste') {
                    \Sofrexa\Modules\Stock\Stock::settleVoid($l['id'], 'waste');
                } elseif ($stock === 'pending') {
                    $ask[] = [$l['id'], (float) $l['qty']];
                }
            }
            Db::save('orders', ['id' => $orderId, 'status' => 'void', 'closed_at' => Clock::ms(), 'note' => trim(($o['note'] ?? '') . ' · iptal: ' . $reason, ' ·')]);
            self::recalc($orderId);
            \Sofrexa\Modules\Customers\Loyalty::refund($orderId);
        });
        foreach ($ask as [$id, $qty]) {
            Tickets::void($orderId, $id, $qty, $reason); // the kitchen stops cooking it
            self::askKitchen($o, $id);
        }
        \Sofrexa\Modules\QrOrder\QrOrders::closeSessions($o['table_id']);
        self::ended($orderId); // its plates are cancelled: the pass has nothing left to hand over
        Audit::log('order.void', self::where($o) . ' · ' . Money::fmt((int) $o['total'], false, 'tr') . ' · sebep: ' . $reason, 'order', $orderId);
    }

    /**
     * A bill has ended — paid, cancelled, merged into another, a guest order turned down — and every way of ending one
     * comes here (decision 50). The alerts about the bill end with it: its bill request, a waiter call, a guest order
     * to approve. "Food is ready" is about plates at the pass, not the bill: it is built again from them, so the plates
     * of a cancelled bill stop ringing, a table's plates carried out at payment too, and a paid take-away's bag still
     * waiting at the pass keeps calling the till until it is handed over.
     */
    public static function ended(string $orderId): void
    {
        Notify::closeFor($orderId, null, ['ready']);
        \Sofrexa\Modules\Kitchen\Kitchen::announce($orderId);
    }

    // ------------------------------------------------------------ helpers

    /**
     * A bag out with the courier or delivered takes no more dishes, and is neither merged nor split: what is added now
     * would never reach the guest, and the kitchen would cook it for nobody (found by the random steps, audit 8).
     */
    private static function notLeft(array $o): void
    {
        $d = is_array($o['delivery'] ?? null) ? $o['delivery'] : json_arr($o['delivery'] ?? null);
        if (in_array($d['stage'] ?? '', ['way', 'done'], true)) {
            throw new \InvalidArgumentException(I18n::t('deliv.err_left'));
        }
    }

    /**
     * Recalculates subtotal, discount, total and paid from lines, discounts and payments. A percentage discount follows the
     * bill (10% stays 10% when dishes are added or voided); fixed amounts stay as given, never above the subtotal.
     */
    public static function recalc(string $orderId): array
    {
        $sub = (int) Db::value("SELECT COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void'", [$orderId]);
        $disc = self::discountTotal(\Sofrexa\Modules\Customers\Loyalty::activeDiscounts($orderId), $sub);
        $paid = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ?', [$orderId]);
        Db::save('orders', ['id' => $orderId, 'subtotal' => $sub, 'discount' => $disc, 'total' => $sub - $disc, 'paid' => $paid]);
        return Db::row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    }

    /** VAT per rate for the receipt and reports: [rate => [gross, vat]]; the gross parts always add up to the bill total. */
    public static function vat(string $orderId): array
    {
        $o = Db::row('SELECT total FROM orders WHERE id = ?', [$orderId]);
        return self::vatSplit(Db::pairs("SELECT vat_rate, SUM(ROUND(qty * (unit_price + mods_price))) FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void' GROUP BY vat_rate", [$orderId]), (int) ($o['total'] ?? 0));
    }

    /**
     * Spreads a bill's total over its VAT rates in proportion to the lines (the discount comes off every rate alike).
     * Whole kuruş that rounding leaves over go to the largest remainders (ties: the higher rate), so the parts add up to
     * the total exactly and the receipt, the Z report and the accountant's file show the same figures.
     * $gross: rate => line amount before the discount. Returns rate => [gross, vat].
     */
    public static function vatSplit(array $gross, int $total): array
    {
        $gross = array_filter(array_map('intval', $gross), static fn(int $g): bool => $g > 0);
        $sub = array_sum($gross);
        if ($sub <= 0) {
            return [];
        }
        $parts = [];
        $rest = [];
        foreach ($gross as $rate => $g) {
            $exact = $g * $total / $sub;
            $parts[(string) (float) $rate] = (int) floor($exact);
            $rest[(string) (float) $rate] = $exact - floor($exact);
        }
        $left = $total - array_sum($parts);
        uksort($rest, static fn(string $a, string $b): int => [$rest[$b], (float) $b] <=> [$rest[$a], (float) $a]);
        foreach (array_keys($rest) as $rate) {
            if ($left <= 0) {
                break;
            }
            $parts[$rate]++;
            $left--;
        }
        ksort($parts, SORT_NUMERIC);
        $out = [];
        foreach ($parts as $rate => $g) {
            $out[(string) $rate] = [$g, Money::vatOf($g, (float) $rate)];
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
