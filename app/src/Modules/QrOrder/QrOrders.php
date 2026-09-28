<?php
declare(strict_types=1);

namespace Sofrexa\Modules\QrOrder;

use Sofrexa\Core\{App, Audit, Clock, Db, I18n, RateLimit, Settings};
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\Modules\Orders\{Assign, Notify, Orders};

/**
 * QR ordering at the table (PLAN §5.10, Figma Q1–Q4 and W5).
 *
 * A guest scans the table card, orders from the QR menu and pays at the table. Guest orders are made where the guest
 * is served (normally the web copy) as orders with channel 'qr' and status 'pending'. The till (the PC, or a web copy
 * that runs alone) takes them in: the first order of a table session waits for a waiter's approval (W5); once a session
 * is approved, the next orders go straight to the kitchen until the table's bill is settled, which closes the session.
 * An accepted order's lines join the table's open bill (or the order becomes the bill) and are sent to the kitchen.
 * The web copy never changes the till's rows: requests from the guest (bill) travel as notifications.
 */
final class QrOrders
{
    public const MAX_LINES = 30;
    public const MAX_QTY = 20;

    /** The table of a QR card: its code, or on old cards a table number that only one table has. */
    public static function table(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        $sql = 'SELECT t.*, a.name AS area_name, a.names AS area_names FROM tables t JOIN areas a ON a.id = t.area_id WHERE t.deleted = 0 AND a.deleted = 0';
        $t = Db::row($sql . ' AND t.code = ?', [$code]);
        if (!$t && ctype_digit($code)) {
            $rows = Db::rows($sql . ' AND t.number = ?', [(string) (int) $code]);
            $t = count($rows) === 1 ? $rows[0] : null;
        }
        return $t ?: null;
    }

    /** "Masa 7 · Bahçe" in the guest's language. */
    public static function label(array $t): string
    {
        return I18n::t('qr.table_area', ['n' => $t['number'], 'area' => tn(json_arr($t['area_names']) ?: $t['area_name'])]);
    }

    /** This copy takes guest orders in itself: the till PC, or a web copy that has no PC behind it. */
    public static function owner(): bool
    {
        return App::isPc() || (string) App::config('sync.key', '') === '' || \Sofrexa\Sync\Emergency::on();
    }

    /** open · off (switched off in the settings) · offline (the web copy has not heard from the till for a while). */
    public static function availability(): string
    {
        if (!Settings::get('qr.enabled', true)) {
            return 'off';
        }
        if (App::isWeb() && !\Sofrexa\Sync\Emergency::on() && \Sofrexa\Sync\Status::get()['state'] === 'offline') {
            return 'offline';
        }
        return 'open';
    }

    // ------------------------------------------------------------ menu

    /**
     * Categories with the items shown on the QR menu (or, with $flag show_online, on online ordering), ready for the
     * guest page with names in the guest's language.
     * @return array<int, array{id: string, name: string, items: array}>
     */
    public static function menu(string $flag = 'show_qr'): array
    {
        $flag = $flag === 'show_online' ? 'show_online' : 'show_qr';
        $groups = [];
        foreach (Menu::groups() as $g) {
            $groups[$g['id']] = $g;
        }
        $links = [];
        foreach (Db::rows('SELECT item_id, group_id FROM item_modifier_groups WHERE deleted = 0 ORDER BY sort') as $l) {
            if (isset($groups[$l['group_id']])) {
                $links[$l['item_id']][] = $l['group_id'];
            }
        }
        $cats = [];
        foreach (Db::rows('SELECT * FROM categories WHERE deleted = 0 AND active = 1' . ($flag === 'show_online' ? ' AND show_online = 1' : '') . ' ORDER BY sort, id') as $c) {
            $cats[$c['id']] = ['id' => $c['id'], 'name' => tn($c['names']), 'items' => []];
        }
        foreach (Menu::items() as $i) {
            if (!$i[$flag] || !$i['active'] || !isset($cats[$i['category_id']])) {
                continue;
            }
            $opts = [];
            foreach ($links[$i['id']] ?? [] as $gid) {
                $g = $groups[$gid];
                $opts[] = [
                    'id' => $g['id'], 'name' => tn($g['names']), 'min' => (int) $g['min_sel'], 'max' => (int) $g['max_sel'],
                    'multi' => $g['kind'] === 'multi' || (int) $g['max_sel'] > 1,
                    'options' => array_map(static fn(array $m): array => ['id' => $m['id'], 'name' => tn($m['names']), 'price' => (int) $m['price']], $g['options']),
                ];
            }
            $promo = \Sofrexa\Modules\Menu\Promotions::best($i, $flag === 'show_online' ? 'online' : 'table');
            $cats[$i['category_id']]['items'][] = [
                'id' => $i['id'],
                'name' => tn($i['names']),
                'desc' => tn($i['descs']),
                'price' => $promo ? \Sofrexa\Modules\Menu\Promotions::price((int) $i['price'], (float) $promo['pct']) : (int) $i['price'],
                'was' => $promo ? (int) $i['price'] : null,
                'promo' => $promo ? tn($promo['names']) : null,
                'photo' => Menu::photoUrl($i['image'], 400),
                'orderable' => $i['orderable'],
                'groups' => $opts,
            ];
        }
        return array_values(array_filter($cats, static fn(array $c): bool => (bool) $c['items']));
    }

    // ------------------------------------------------------------ guest orders

    /** The table's open session (the guests sitting there now), if any. */
    public static function session(string $tableId): ?array
    {
        return Db::row('SELECT * FROM qr_sessions WHERE table_id = ? AND closed_at IS NULL AND deleted = 0 ORDER BY opened_at DESC, rowid DESC LIMIT 1', [$tableId]) ?: null;
    }

    /**
     * Whether the next order from this phone at this table waits for a waiter. A waiter's approval trusts the phone it came
     * from, not the whole table: a photo of the table card on another phone is asked about once (decision 37).
     */
    public static function needsApproval(?array $session, ?string $device = null): bool
    {
        if (!Settings::get('qr.require_first_approval', true)) {
            return false;
        }
        return !($session && $session['approved_at'] && $device !== null && in_array($device, json_arr((string) ($session['devices'] ?? '[]')), true));
    }

    /** This phone, as the orders and the table session know it: a hash of a random id kept in its session. */
    public static function device(): string
    {
        $_SESSION['qr_dev'] ??= bin2hex(random_bytes(16));
        return substr(hash('sha256', 'qr-device:' . $_SESSION['qr_dev']), 0, 32);
    }

    /** An order only counts once all its lines are here (a web order may arrive in two sync batches). */
    public static function complete(array $o): bool
    {
        return $o['lines_expected'] === null
            || (int) Db::value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND deleted = 0', [$o['id']]) >= (int) $o['lines_expected'];
    }

    /**
     * Places a guest's order. $lines: [{item, qty, mods: [ids], note}]. Returns [order id, session id].
     * @throws \InvalidArgumentException with a message for the guest
     */
    public static function submit(array $table, array $lines, string $note = ''): array
    {
        if (self::availability() !== 'open') {
            throw new \InvalidArgumentException(I18n::t('qr.err_closed'));
        }
        if (!RateLimit::hit('qr:order:' . $table['id'] . ':' . \Sofrexa\Core\Net::clientIp(), 8, 600_000)) {
            throw new \InvalidArgumentException(I18n::t('qr.err_limit'));
        }
        $clean = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            $item = (string) ($l['item'] ?? '');
            if ($qty < 1 || $item === '') {
                continue;
            }
            $clean[] = ['item' => $item, 'qty' => min(self::MAX_QTY, $qty), 'mods' => array_values(array_filter(array_map('strval', (array) ($l['mods'] ?? [])))),
                'note' => mb_substr(trim((string) ($l['note'] ?? '')), 0, 120)];
        }
        if (!$clean) {
            throw new \InvalidArgumentException(I18n::t('qr.err_empty'));
        }
        foreach ($clean as $l) {
            self::checkItem($l['item'], $l['mods']);
        }
        $device = self::device();
        [$orderId, $sessionId] = Db::tx(static function () use ($table, $clean, $note, $device): array {
            $s = self::session($table['id']);
            $sid = $s['id'] ?? Db::save('qr_sessions', ['table_id' => $table['id'], 'token' => bin2hex(random_bytes(8)), 'opened_at' => Clock::ms()]);
            $oid = Orders::create('qr', ['status' => 'pending', 'table_id' => $table['id'], 'qr_session_id' => $sid, 'waiter_id' => null,
                'note' => mb_substr(trim($note), 0, 200) ?: null]);
            foreach ($clean as $l) {
                $lid = Orders::addItem($oid, $l['item'], $l['qty'], $l['mods'], $l['note']);
                Db::save('order_items', ['id' => $lid, 'src_order_id' => $oid]);
            }
            // written last, so the order row is queued for sync after its lines
            Db::save('orders', ['id' => $oid, 'qr_device' => $device,
                'lines_expected' => (int) Db::value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND deleted = 0', [$oid])]);
            return [$oid, $sid];
        });
        if (self::owner()) {
            self::intake();
        }
        return [$orderId, $sessionId];
    }

    /** Only items of the QR menu, with the required choices made. */
    private static function checkItem(string $itemId, array $mods): void
    {
        $i = Db::row('SELECT i.names, i.show_qr, i.active FROM items i WHERE i.id = ? AND i.deleted = 0', [$itemId]);
        if (!$i || !$i['show_qr'] || !$i['active']) {
            throw new \InvalidArgumentException(I18n::t('qr.err_item'));
        }
        foreach (Db::rows('SELECT g.* FROM item_modifier_groups l JOIN modifier_groups g ON g.id = l.group_id AND g.deleted = 0 WHERE l.item_id = ? AND l.deleted = 0', [$itemId]) as $g) {
            $n = $mods ? (int) Db::value('SELECT COUNT(*) FROM modifiers WHERE group_id = ? AND deleted = 0 AND id IN (' . Db::in($mods) . ')', [$g['id'], ...$mods]) : 0;
            if ($n < (int) $g['min_sel'] || ((int) $g['max_sel'] > 0 && $n > (int) $g['max_sel'])) {
                throw new \InvalidArgumentException(I18n::t('qr.err_required', ['name' => tn($g['names'])]));
            }
        }
    }

    // ------------------------------------------------------------ at the till

    /**
     * Takes new guest orders in (after each sync round on the PC, at once on a lone copy): straight to the kitchen when
     * the table's session is approved, otherwise a "QR order to approve" alert for the table's waiter. Also applies
     * the bill requests made on the QR menu. Returns the number of things handled.
     */
    public static function intake(): int
    {
        if (!self::owner()) {
            return 0;
        }
        $n = 0;
        foreach (Db::rows("SELECT id, qr_session_id, qr_device, lines_expected FROM orders WHERE channel = 'qr' AND status = 'pending' AND intake_at IS NULL AND deleted = 0 ORDER BY opened_at, rowid") as $o) {
            if (!self::complete($o)) {
                continue; // the rest of its lines come with the next sync batch
            }
            $s = $o['qr_session_id'] ? Db::row('SELECT * FROM qr_sessions WHERE id = ?', [$o['qr_session_id']]) : null;
            $direct = !self::needsApproval($s && !$s['closed_at'] ? $s : null, $o['qr_device']);
            try {
                if ($direct) {
                    self::accept($o['id'], null);
                }
            } catch (\InvalidArgumentException | \Sofrexa\Core\ValidationError) {
                // it cannot go to the kitchen as it is (a dish run out since): nothing of it was taken, and it waits for
                // the waiter like an order to approve, instead of being tried again at every sync round
                $direct = false;
            }
            if (!$direct) {
                Db::save('orders', ['id' => $o['id'], 'intake_at' => Clock::ms()]);
                self::alert($o['id']);
            }
            $n++;
        }
        foreach (Db::rows("SELECT DISTINCT n.ref_id FROM notifications n JOIN orders o ON o.id = n.ref_id
            WHERE n.kind = 'bill' AND n.ref_type = 'order' AND n.done_at IS NULL AND n.deleted = 0 AND n.body LIKE '%\"qr\":true%'
              AND o.bill_at IS NULL AND o.status IN ('open', 'billed') AND o.deleted = 0") as $b) {
            Db::save('orders', ['id' => $b['ref_id'], 'bill_at' => Clock::ms()]);
            $n++;
        }
        return $n;
    }

    /**
     * "QR order waiting" for the waiter of the table's bill. A table nobody owns yet is shared out among the
     * waiters on shift (Assign), so the orders do not all land on whoever looks at their phone first.
     */
    private static function alert(string $orderId): void
    {
        $o = Orders::get($orderId);
        $main = self::mainOrder($o['table_id'], $orderId);
        // the table's waiter while on shift; otherwise shared out like a table nobody owns
        $waiter = Notify::recipient($main['waiter_id'] ?? null) ?? Assign::toWaiter($orderId);
        $lines = array_filter($o['lines'], static fn(array $l): bool => $l['status'] !== 'void');
        Notify::push('qr', ['where' => Orders::where($o), 'what' => I18n::t('qr.n_items', ['n' => count($lines)], 'tr') . ' · ' . \Sofrexa\Core\Money::fmt((int) $o['total'], false, 'tr')],
            $waiter, 'waiter', $orderId);
    }

    /** The table's own bill (open or billed), not a guest order waiting for approval. */
    public static function mainOrder(string $tableId, string $except = ''): ?array
    {
        return Db::row("SELECT * FROM orders WHERE table_id = ? AND status IN ('open', 'billed') AND deleted = 0 AND id <> ? ORDER BY parent_id IS NOT NULL, opened_at LIMIT 1", [$tableId, $except]) ?: null;
    }

    /** Guest orders of a table waiting for approval, oldest first. */
    public static function pending(string $tableId): array
    {
        return Db::rows("SELECT * FROM orders WHERE table_id = ? AND channel = 'qr' AND status = 'pending' AND deleted = 0 ORDER BY opened_at, rowid", [$tableId]);
    }

    /**
     * "Onayla" (a waiter, $userId) or an order of an approved session ($userId null): the session counts as approved,
     * the lines join the table's bill (or the order becomes the bill) and go to the kitchen.
     */
    public static function accept(string $orderId, ?string $userId): string
    {
        self::pendingOrder($orderId);
        $now = Clock::ms();
        // one step (audit 8, O14): the approval, the phone trusted from now on, the lines joining the table's bill and
        // going to the kitchen — all or nothing. A dish run out since the guest ordered refuses the whole approval and the
        // order stays waiting, to be approved again once the dish is back or its line changed.
        [$o, $target] = Db::tx(static function () use ($orderId, $userId, $now): array {
            $o = self::pendingOrder($orderId); // read inside the lock: approved once, whoever taps
            $lines = array_column(Db::rows("SELECT id FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId]), 'id');
            // the waiter who approved, the one the order was shared out to, or — when neither — the least busy on shift
            $pick = $userId ?: ($o['waiter_id'] ?: Assign::pick());
            $target = Db::tx(static function () use ($o, $userId, $now, $lines, $pick): string {
                if ($userId && $o['qr_session_id'] && ($s = Db::row('SELECT approved_at, devices FROM qr_sessions WHERE id = ?', [$o['qr_session_id']]))) {
                    // the waiter saw this phone's order: the phone may order on its own from now on
                    $devices = json_arr((string) $s['devices']);
                    if ($o['qr_device'] && !in_array($o['qr_device'], $devices, true)) {
                        $devices[] = $o['qr_device'];
                    }
                    Db::save('qr_sessions', ['id' => $o['qr_session_id'], 'devices' => array_values($devices)]
                        + ($s['approved_at'] ? [] : ['approved_at' => $now, 'approved_by' => $userId]));
                }
                $stamp = ['id' => $o['id'], 'intake_at' => $o['intake_at'] ?: $now, 'approved_at' => $now, 'approved_by' => $userId];
                $main = self::mainOrder((string) $o['table_id'], $o['id']);
                if (!$main) {
                    Db::save('orders', $stamp + ['status' => 'open', 'waiter_id' => $pick]
                        + ($pick && !$o['assigned_at'] ? ['assigned_at' => $now] : []));
                    return $o['id'];
                }
                foreach ($lines as $id) {
                    Db::save('order_items', ['id' => $id, 'order_id' => $main['id']]);
                }
                Db::save('orders', $stamp + ['status' => 'void', 'closed_at' => $now, 'merged_into' => $main['id']]);
                Orders::recalc($o['id']);
                return $main['id'];
            });
            Orders::send($target, $lines);
            Orders::recalc($target);
            return [$o, $target];
        });
        if ($target === $orderId) {
            Notify::closeFor($orderId, 'qr'); // it is the table's bill now: only its "approve" alert is over
        } else {
            Orders::ended($orderId); // merged into the table's bill: the guest order is over
        }
        Audit::log($userId ? 'order.qr_approve' : 'order.qr_direct', Orders::where($o) . ' · ' . \Sofrexa\Core\Money::fmt((int) $o['total'], false, 'tr'), 'order', $target);
        return $target;
    }

    /** "Reddet": the guest order is cancelled before anything reached the kitchen. */
    public static function reject(string $orderId): void
    {
        $o = self::pendingOrder($orderId);
        $now = Clock::ms();
        Db::save('orders', ['id' => $orderId, 'status' => 'void', 'closed_at' => $now, 'intake_at' => $o['intake_at'] ?: $now]);
        Orders::ended($orderId);
        Audit::log('order.qr_reject', Orders::where($o) . ' · ' . \Sofrexa\Core\Money::fmt((int) $o['total'], false, 'tr'), 'order', $orderId);
    }

    private static function pendingOrder(string $orderId): array
    {
        $o = Db::row('SELECT * FROM orders WHERE id = ? AND deleted = 0', [$orderId]);
        if (!$o || $o['channel'] !== 'qr') {
            throw new \Sofrexa\Core\HttpError(404);
        }
        if ($o['status'] !== 'pending') {
            throw new \InvalidArgumentException(I18n::t('qr.err_handled'));
        }
        if (!self::complete($o)) {
            throw new \InvalidArgumentException(I18n::t('sync.err_incomplete'));
        }
        return $o;
    }

    /** The bill is settled (paid or closed): the guests have gone, the next QR order needs approval again. */
    public static function closeSessions(?string $tableId): void
    {
        if (!$tableId || self::mainOrder($tableId)) {
            return;
        }
        foreach (Db::rows('SELECT id FROM qr_sessions WHERE table_id = ? AND closed_at IS NULL AND deleted = 0', [$tableId]) as $s) {
            Db::save('qr_sessions', ['id' => $s['id'], 'closed_at' => Clock::ms()]);
        }
    }

    // ------------------------------------------------------------ requests from the table

    /** "Garson çağır": an alert for the table's waiter (at most one a minute per table). */
    public static function callWaiter(array $table): bool
    {
        if (!RateLimit::hit('qr:call:' . $table['id'], 1, 60_000)) {
            return false;
        }
        $main = self::mainOrder($table['id']);
        $where = 'Masa ' . $table['number'];
        Notify::push('call', ['where' => $where, 'qr' => true], Notify::recipient($main['waiter_id'] ?? null), 'waiter', $main['id'] ?? null);
        return true;
    }

    /** "Hesap iste": the table's bill is asked for; the till marks it when the request arrives. */
    public static function requestBill(array $table): bool
    {
        $main = self::mainOrder($table['id']);
        if (!$main || !RateLimit::hit('qr:bill:' . $table['id'], 1, 60_000)) {
            return false;
        }
        Notify::toWaiter('bill', $main + ['table_no' => $table['number']], ['qr' => true]);
        if (self::owner() && !$main['bill_at']) {
            Db::save('orders', ['id' => $main['id'], 'bill_at' => Clock::ms()]);
        }
        return true;
    }

    // ------------------------------------------------------------ what the guest sees

    /**
     * Status page (Q3) for the orders this phone placed: the latest one with its timeline, and the table's whole bill.
     * $orderIds come from the guest's session. Returns null when none of them belongs to the table any more.
     */
    public static function status(array $table, array $orderIds): ?array
    {
        if (!$orderIds) {
            return null;
        }
        $orders = Db::rows('SELECT o.*, u.name AS approver FROM orders o LEFT JOIN users u ON u.id = o.approved_by
            WHERE o.id IN (' . Db::in($orderIds) . ") AND o.table_id = ? AND o.channel = 'qr' AND o.deleted = 0 ORDER BY o.opened_at, o.rowid", [...$orderIds, $table['id']]);
        if (!$orders) {
            return null;
        }
        $o = end($orders);
        $lines = Db::rows("SELECT l.*, i.image, i.prep_minutes, i.names AS item_names FROM order_items l LEFT JOIN items i ON i.id = l.item_id
            WHERE (l.src_order_id = ? OR l.order_id = ?) AND l.deleted = 0 ORDER BY l.created_at, l.rowid", [$o['id'], $o['id']]);
        $live = array_values(array_filter($lines, static fn(array $l): bool => $l['status'] !== 'void'));
        $rejected = $o['status'] === 'void' && !$o['merged_into'];
        if ($rejected) {
            $state = 'rejected';
        } elseif ($o['status'] === 'pending') {
            $state = 'pending';
        } elseif ($live && !array_filter($live, static fn(array $l): bool => $l['status'] !== 'served')) {
            $state = 'served';
        } elseif ($live && !array_filter($live, static fn(array $l): bool => !in_array($l['status'], ['ready', 'served'], true))) {
            $state = 'ready';
        } else {
            $state = 'kitchen';
        }
        $sentAt = array_filter(array_map(static fn(array $l): int => (int) $l['sent_at'], $live));
        $servedAt = array_filter(array_map(static fn(array $l): int => (int) $l['served_at'], $live));
        $steps = [['done', 'check', I18n::t('qr.step_sent'), (int) $o['opened_at']]];
        if ($state === 'pending') {
            $steps[] = ['current', 'hand', I18n::t('qr.step_wait'), null];
        } elseif ($o['approved_by']) {
            $steps[] = ['done', 'check', I18n::t('qr.step_approved', ['name' => first_name((string) $o['approver'])]), (int) $o['approved_at']];
        }
        if (!$rejected) {
            $kitchen = match ($state) { 'pending' => 'upcoming', 'kitchen' => 'current', default => 'done' };
            $steps[] = [$kitchen, 'chef-hat', I18n::t('qr.step_kitchen'), $kitchen === 'done' && $sentAt ? min($sentAt) : null];
            $serve = match ($state) { 'served' => 'done', 'ready' => 'current', default => 'upcoming' };
            $steps[] = [$serve, 'utensils', I18n::t('qr.step_serve'), $serve === 'done' && $servedAt ? max($servedAt) : null];
        }
        $prep = max(array_map(static fn(array $l): int => (int) $l['prep_minutes'], $live) ?: [0]);
        $eta = $prep > 0 ? $prep . '–' . ($prep + 5) : (string) Settings::get('qr.eta_minutes', '15–20');

        // the whole bill of the table: its open orders and this phone's orders still waiting for approval
        $pendingMine = array_column(array_filter($orders, static fn(array $x): bool => $x['status'] === 'pending'), 'id');
        $tab = Db::rows("SELECT l.*, i.image, i.names AS item_names FROM order_items l JOIN orders o ON o.id = l.order_id LEFT JOIN items i ON i.id = l.item_id
            WHERE l.deleted = 0 AND l.status <> 'void' AND o.deleted = 0 AND ((o.table_id = ? AND o.status IN ('open', 'billed'))"
            . ($pendingMine ? ' OR o.id IN (' . Db::in($pendingMine) . ')' : '') . ') ORDER BY l.created_at, l.rowid', [$table['id'], ...$pendingMine]);
        $total = array_sum(array_map(static fn(array $l): int => (int) round((float) $l['qty'] * ((int) $l['unit_price'] + (int) $l['mods_price'])), $tab));
        return [
            'order' => $o,
            'state' => $state,
            'steps' => $steps,
            'eta' => $eta,
            'lines' => $live,
            'tab' => $tab,
            'total' => $total,
            'can_bill' => (bool) self::mainOrder($table['id']),
        ];
    }

    /** A line's dish name in the guest's language (the line keeps the Turkish name for the tickets). */
    public static function lineName(array $l): string
    {
        return tn($l['item_names'] ?? null) ?: (string) $l['name'];
    }

    /** "Acısız, az pişmiş, çocuk için" — options and the guest's note of a line, in the guest's language. */
    public static function lineNote(array $l): string
    {
        static $names = null;
        $names ??= Db::pairs('SELECT id, names FROM modifiers');
        $parts = array_map(static fn(array $m): string => isset($names[$m['id'] ?? '']) ? tn($names[$m['id']]) : (string) $m['name'], json_arr($l['mods']));
        if (trim((string) $l['note']) !== '') {
            $parts[] = trim((string) $l['note']);
        }
        return implode(', ', $parts);
    }
}
