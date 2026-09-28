<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Audit, Auth, Clock, Db, I18n, Money, Settings, ValidationError};
use Sofrexa\Modules\Customers\Customers;

/**
 * Takeaway, pickup and delivery orders (C4, C5). The order's 'delivery' JSON keeps: phone, address,
 * address_id, courier_id, pay_hint (cash | card | account), currency, cash_given, note, pickup_at,
 * stage (kitchen | ready | way | done), ready_at, out_at, done_at.
 * A delivered order stays open until the courier hands in the money ("Hesaplaş"); then the payments
 * are recorded with the courier and the order closes.
 *
 * Paying and handing over are two different ends (audit 7, E02; decision 54): a bag paid before it leaves stays on the
 * board — its courier chosen, sent out, delivered — until it is handed over; one delivered and not yet paid waits for
 * the courier's money. The board's "Hazır" is the kitchen's own "Hazır" (E03), the bag cannot leave while a dish is
 * still cooking, and the delivery fee — no dish at all — is never waited for (E04).
 */
final class Delivery
{
    public const CHANNELS = ['delivery', 'takeaway', 'online'];
    public const FEE_NAME = 'Teslimat ücreti';
    /** How far back a paid bag not yet handed over is still looked for on the board. */
    public const PAID_WINDOW_MS = 12 * 3_600_000;

    /** The delivery fee as a line of its own that never goes to the kitchen (phone and online delivery alike, audit 7 E01). */
    public static function addFee(string $orderId): void
    {
        $fee = (int) Settings::get('online.delivery_fee', 0);
        if ($fee > 0) {
            // round 0: no ticket of the kitchen's, so the first real one is still round 1
            Db::save('order_items', ['order_id' => $orderId, 'name' => self::FEE_NAME, 'qty' => 1, 'unit_price' => $fee, 'station' => 'kitchen', 'round' => 0,
                'status' => 'served', 'created_at' => Clock::ms(), 'served_at' => Clock::ms()]);
            Orders::recalc($orderId);
        }
    }

    /**
     * The dishes of an order — the lines the kitchen makes, not the delivery fee (a line served without ever being sent):
     * how many, how many still in the kitchen (new or sent), done (ready or served), handed over (served), and when last.
     * @return array{prep:int, cooking:int, done:int, served:int, served_at:?int}
     */
    public static function plates(string $orderId): array
    {
        $r = Db::row("SELECT COUNT(*) AS prep, COALESCE(SUM(status IN ('new', 'sent')), 0) AS cooking, COALESCE(SUM(status IN ('ready', 'served')), 0) AS done,
            COALESCE(SUM(status = 'served'), 0) AS served, MAX(served_at) AS served_at FROM order_items
            WHERE order_id = ? AND deleted = 0 AND status <> 'void' AND NOT (status = 'served' AND sent_at IS NULL)", [$orderId]);
        return ['prep' => (int) $r['prep'], 'cooking' => (int) $r['cooking'], 'done' => (int) $r['done'], 'served' => (int) $r['served'],
            'served_at' => $r['served_at'] !== null ? (int) $r['served_at'] : null];
    }

    /** Handed over: delivered at the door ("Teslim edildi"), or — a take-away or pickup — every dish handed over at the counter. */
    public static function handedOver(array $o): bool
    {
        $d = is_array($o['delivery'] ?? null) ? $o['delivery'] : json_arr($o['delivery'] ?? null);
        if (($d['stage'] ?? '') === 'done') {
            return true;
        }
        if (self::isDelivery($o)) {
            return false;
        }
        $p = self::plates($o['id']);
        return $p['prep'] > 0 && $p['served'] >= $p['prep'];
    }

    /** An order the board still works on: open, or paid and not yet handed over. */
    public static function order(string $orderId): array
    {
        $o = Orders::get($orderId);
        $o['delivery'] = is_array($o['delivery'] ?? null) ? $o['delivery'] : json_arr($o['delivery'] ?? null);
        if (in_array($o['status'], Orders::OPEN, true) || ($o['status'] === 'paid' && in_array($o['channel'], self::CHANNELS, true) && !self::handedOver($o))) {
            return $o;
        }
        throw new \InvalidArgumentException(I18n::t('order.err_closed'));
    }

    /**
     * Creates a phone delivery or pickup order from the C4 screen and sends it to the kitchen.
     * $in: type (delivery|pickup), customer_id?, name, phone, address_id?, address?, address_label?,
     * courier_id?, pay (cash|card|account), cash_given (lira), note, pickup_at, items [{item_id, qty, mods[], note}].
     */
    public static function create(array $in): string
    {
        $type = ($in['type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $items = array_values(array_filter((array) ($in['items'] ?? []), static fn($i): bool => is_array($i) && !empty($i['item_id'])));
        if (!$items) {
            throw new ValidationError(['items' => I18n::t('deliv.err_empty')]);
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($type === 'delivery' && phone_norm($phone) === '') {
            throw new ValidationError(['phone' => I18n::t('deliv.err_phone')]);
        }
        $customerId = null;
        if ($phone !== '' || trim((string) ($in['name'] ?? '')) !== '') {
            $customerId = Customers::quickSave((string) ($in['name'] ?? ''), $phone, ($in['customer_id'] ?? '') ?: null);
        }
        $address = '';
        $addressId = ($in['address_id'] ?? '') ?: null;
        if ($type === 'delivery') {
            if (!$addressId && trim((string) ($in['address'] ?? '')) !== '') {
                $addressId = Customers::addAddress($customerId, (string) $in['address'], (string) ($in['address_label'] ?? ''));
            }
            $address = $addressId ? (string) Db::value('SELECT address FROM customer_addresses WHERE id = ? AND customer_id = ?', [$addressId, $customerId]) : '';
            if ($address === '') {
                throw new ValidationError(['address' => I18n::t('deliv.err_address')]);
            }
        }
        $pay = in_array($in['pay'] ?? '', ['cash', 'card', 'account'], true) ? $in['pay'] : 'cash';
        if ($pay === 'account') {
            Accounts::assertCredit($customerId, 0);
        }
        $courier = ($in['courier_id'] ?? '') ?: null;
        if ($courier && !self::isCourier($courier)) {
            $courier = null;
        }
        $customer = $customerId ? Customers::get($customerId) : null;
        $eta = (string) Settings::get('online.eta_minutes', '35–45');
        $orderId = Db::tx(static function () use ($type, $customerId, $customer, $phone, $address, $addressId, $pay, $courier, $in, $items, $eta): string {
            $id = Orders::create($type === 'pickup' ? 'takeaway' : 'delivery', [
                'customer_id' => $customerId,
                'label' => $customer['name'] ?? null,
                'delivery' => [
                    'phone' => $phone, 'address' => $address, 'address_id' => $addressId, 'courier_id' => $type === 'delivery' ? $courier : null,
                    'pay_hint' => $pay, 'cash_given' => Money::parse($in['cash_given'] ?? 0), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200),
                    'pickup_at' => $type === 'pickup' ? mb_substr((string) ($in['pickup_at'] ?? ''), 0, 5) : null,
                    'eta' => $type === 'delivery' ? $eta : null, 'stage' => 'kitchen', 'phone_order' => true,
                ],
            ]);
            foreach ($items as $i) {
                Orders::addItem($id, (string) $i['item_id'], (float) ($i['qty'] ?? 1), (array) ($i['mods'] ?? []), (string) ($i['note'] ?? ''));
            }
            if ($type === 'delivery') {
                self::addFee($id); // what the form showed is what the bill says
            }
            return $id;
        });
        Orders::send($orderId);
        $o = Orders::get($orderId);
        if ($type === 'delivery') {
            Tickets::courier($orderId);
        }
        Audit::log('order.create', Orders::where($o) . ' · ' . ($customer['name'] ?? '') . ' · ' . Money::fmt((int) $o['total'], false, 'tr'), 'order', $orderId);
        return $orderId;
    }

    /** A courier takes it: phone delivery, or an online order for delivery. */
    public static function isDelivery(array $o): bool
    {
        $d = is_array($o['delivery'] ?? null) ? $o['delivery'] : json_arr($o['delivery'] ?? null);
        return $o['channel'] === 'delivery' || ($o['channel'] === 'online' && ($d['type'] ?? '') === 'delivery');
    }

    public static function isCourier(string $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'courier' AND u.active = 1 AND u.deleted = 0", [$userId]);
    }

    /** Takeaway, delivery and online orders on the board with their stage: the open ones, and the paid ones not handed over yet. */
    public static function open(): array
    {
        $out = [];
        $paid = array_filter(Orders::open(self::CHANNELS, ['paid'], Clock::ms() - self::PAID_WINDOW_MS), static fn(array $o): bool => !self::handedOver($o));
        foreach ([...Orders::open(self::CHANNELS), ...$paid] as $o) {
            $o['delivery'] = json_arr($o['delivery']);
            $o['stage'] = self::stage($o);
            $out[] = $o;
        }
        return $out;
    }

    /** pending | kitchen | ready | way | done — from the order and its dishes (every dish ready is ready, whatever was tapped). */
    public static function stage(array $o): string
    {
        $d = is_array($o['delivery']) ? $o['delivery'] : json_arr($o['delivery']);
        if ($o['status'] === 'pending') {
            return 'pending';
        }
        $s = $d['stage'] ?? 'kitchen';
        if (in_array($s, ['way', 'done'], true)) {
            return $s;
        }
        $p = self::plates($o['id']);
        return $p['prep'] > 0 && $p['cooking'] === 0 ? 'ready' : 'kitchen';
    }

    /** C5 columns: pending, kitchen, ready, way (done orders wait for the courier's settlement). */
    public static function board(): array
    {
        $cols = ['pending' => [], 'kitchen' => [], 'ready' => [], 'way' => []];
        foreach (self::open() as $o) {
            if (isset($cols[$o['stage']])) {
                $cols[$o['stage']][] = $o;
            }
        }
        return $cols;
    }

    /** Moves an order along: approve (pending → kitchen), ready, way (out with the courier), done (delivered). */
    public static function move(string $orderId, string $to): void
    {
        $o = $to === 'approve' ? Orders::editable($orderId) : self::order($orderId);
        $d = $o['delivery'];
        $now = Clock::ms();
        // only forward, and only the steps of its kind: kitchen → ready → out with the courier → delivered; a pickup is
        // handed over from the pass. Never back: a bag delivered is not on its way again (the guest would read both).
        $from = $to === 'approve' ? 'pending' : self::stage($o);
        $allowed = match ($to) {
            'approve' => ['pending'],
            'ready' => ['kitchen', 'ready'],
            'way' => self::isDelivery($o) ? ['kitchen', 'ready'] : [],
            'done' => self::isDelivery($o) ? ['way'] : ['kitchen', 'ready'],
            default => throw new \InvalidArgumentException('stage'),
        };
        if (!in_array($from, $allowed, true)) {
            throw new \InvalidArgumentException(I18n::t('deliv.err_stage'));
        }
        switch ($to) {
            case 'approve':
                Db::save('orders', ['id' => $orderId, 'status' => 'open', 'approved_by' => Auth::user()['id'] ?? null]);
                Orders::send($orderId);
                Audit::log('order.approve', Orders::where($o), 'order', $orderId);
                return;
            case 'ready':
                // the kitchen's own "Hazır" for every ticket still cooking: the plates are ready and the till is called,
                // exactly as from the kitchen screen — the board and the kitchen never disagree (audit 7, E03)
                if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$orderId])) {
                    Orders::send($orderId);
                }
                foreach (Db::rows("SELECT DISTINCT round, station FROM order_items WHERE order_id = ? AND status = 'sent' AND deleted = 0 ORDER BY round", [$orderId]) as $t) {
                    \Sofrexa\Modules\Kitchen\Kitchen::ready($orderId, (int) $t['round'], (string) $t['station']);
                }
                $d['stage'] = 'ready';
                $d['ready_at'] = $now;
                break;
            case 'way':
            case 'done':
                // a bag leaves the pass only with its dishes: one still cooking is made ready first ("Hazır"), not left behind
                if (self::plates($orderId)['cooking'] > 0) {
                    throw new \InvalidArgumentException(I18n::t('deliv.err_cooking'));
                }
                if ($to === 'way' && self::isDelivery($o) && empty($d['courier_id'])) {
                    throw new ValidationError(['courier' => I18n::t('deliv.a_courier')]);
                }
                $d['stage'] = $to;
                $d[$to === 'way' ? 'out_at' : 'done_at'] = $now;
                break;
            default:
                throw new \InvalidArgumentException('stage');
        }
        Db::save('orders', ['id' => $orderId, 'delivery' => $d]);
        if ($to !== 'ready') {
            // the bag left the pass — with the courier, or in the guest's hands: its plates are served and stop calling the till
            \Sofrexa\Modules\Kitchen\Kitchen::served($orderId);
        }
    }

    public static function assign(string $orderId, ?string $courierId): void
    {
        $o = self::order($orderId);
        if ($courierId && !self::isCourier($courierId)) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $d = $o['delivery'];
        $d['courier_id'] = $courierId;
        Db::save('orders', ['id' => $orderId, 'delivery' => $d]);
    }

    /** Couriers with today's count, whether they are out, and the cash they hold (delivered, not yet settled). */
    public static function couriers(): array
    {
        $day = Orders::businessDay();
        $rows = Db::rows("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'courier' AND u.active = 1 AND u.deleted = 0 ORDER BY u.name");
        $open = self::open();
        foreach ($rows as &$c) {
            $c['today'] = (int) Db::value("SELECT COUNT(*) FROM orders WHERE channel IN ('delivery', 'online') AND day = ? AND status <> 'void' AND deleted = 0 AND json_extract(delivery, '$.courier_id') = ?", [$day, $c['id']]);
            $c['out'] = false;
            $c['cash'] = ['TRY' => 0];
            $c['orders'] = [];
            foreach ($open as $o) {
                if (($o['delivery']['courier_id'] ?? null) !== $c['id']) {
                    continue;
                }
                if ($o['stage'] === 'way') {
                    $c['out'] = true;
                }
                if ($o['stage'] === 'done') {
                    $c['orders'][] = $o;
                    if (($o['delivery']['pay_hint'] ?? 'cash') === 'cash') {
                        $cur = $o['delivery']['currency'] ?? 'TRY';
                        if ($cur !== 'TRY' && !empty($o['delivery']['amount_fx'])) {
                            $c['cash'][$cur] = ($c['cash'][$cur] ?? 0) + (float) $o['delivery']['amount_fx'];
                        } else {
                            $c['cash']['TRY'] += max(0, (int) $o['total'] - (int) $o['paid']);
                        }
                    }
                }
            }
        }
        return $rows;
    }

    /** "₺1.790 + £30" */
    public static function cashText(array $cash): string
    {
        $parts = [money((int) ($cash['TRY'] ?? 0))];
        foreach ($cash as $cur => $v) {
            if ($cur !== 'TRY' && $v > 0) {
                $parts[] = Money::symbol($cur) . digits(I18n::num((float) $v, fmod((float) $v, 1.0) > 0 ? 2 : 0));
            }
        }
        return implode(' + ', $parts);
    }

    /** The courier hands in the money: every delivered order of theirs is paid and closed. Returns the number settled. */
    public static function settle(string $courierId): int
    {
        $n = 0;
        foreach (self::open() as $o) {
            if (($o['delivery']['courier_id'] ?? null) !== $courierId || $o['stage'] !== 'done') {
                continue;
            }
            $due = max(0, (int) $o['total'] - (int) $o['paid']);
            if ($due > 0) {
                $hint = $o['delivery']['pay_hint'] ?? 'cash';
                $cur = $o['delivery']['currency'] ?? 'TRY';
                $part = ['method' => $hint, 'currency' => 'TRY', 'amount' => $due, 'courier_id' => $courierId];
                if ($hint === 'cash' && $cur !== 'TRY' && !empty($o['delivery']['amount_fx'])) {
                    $part = ['method' => 'cash', 'currency' => $cur, 'amount_fx' => (float) $o['delivery']['amount_fx'], 'courier_id' => $courierId];
                }
                Orders::pay($o['id'], [$part], $o['customer_id'] ?: null, false);
            }
            $n++;
        }
        if ($n > 0) {
            Audit::log('delivery.settle', (string) Db::value('SELECT name FROM users WHERE id = ?', [$courierId]) . ' · ' . $n . ' teslimat', 'user', $courierId);
        }
        return $n;
    }

    /** Today's delivery count and average time from order to door (C5 subtitle). */
    public static function todayStats(): array
    {
        $day = Orders::businessDay();
        $n = (int) Db::value("SELECT COUNT(*) FROM orders WHERE channel = 'delivery' AND day = ? AND status <> 'void' AND deleted = 0", [$day]);
        $avg = Db::value("SELECT AVG(json_extract(delivery, '$.done_at') - opened_at) FROM orders WHERE channel = 'delivery' AND day = ? AND json_extract(delivery, '$.done_at') IS NOT NULL", [$day]);
        return ['n' => $n, 'avg_min' => $avg ? (int) round((float) $avg / 60_000) : 0];
    }
}
