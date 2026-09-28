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
 */
final class Delivery
{
    public const CHANNELS = ['delivery', 'takeaway', 'online'];

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

    public static function isCourier(string $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'courier' AND u.active = 1 AND u.deleted = 0", [$userId]);
    }

    /** Open takeaway, delivery and online orders with their stage. */
    public static function open(): array
    {
        $out = [];
        foreach (Orders::open(self::CHANNELS) as $o) {
            $o['delivery'] = json_arr($o['delivery']);
            $o['stage'] = self::stage($o);
            $out[] = $o;
        }
        return $out;
    }

    /** pending | kitchen | ready | way | done — from the order and its lines. */
    public static function stage(array $o): string
    {
        $d = is_array($o['delivery']) ? $o['delivery'] : json_arr($o['delivery']);
        if ($o['status'] === 'pending') {
            return 'pending';
        }
        $s = $d['stage'] ?? 'kitchen';
        if ($s === 'kitchen' && (int) ($o['line_count'] ?? 0) > 0 && (int) ($o['ready'] ?? 0) >= (int) $o['line_count']) {
            return 'ready';
        }
        return in_array($s, ['kitchen', 'ready', 'way', 'done'], true) ? $s : 'kitchen';
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
        $o = Orders::editable($orderId);
        $d = $o['delivery'];
        $now = Clock::ms();
        switch ($to) {
            case 'approve':
                Db::save('orders', ['id' => $orderId, 'status' => 'open', 'approved_by' => Auth::user()['id'] ?? null]);
                Orders::send($orderId);
                Audit::log('order.approve', Orders::where($o), 'order', $orderId);
                return;
            case 'ready':
                $d['stage'] = 'ready';
                $d['ready_at'] = $now;
                break;
            case 'way':
                if ($o['channel'] === 'delivery' && empty($d['courier_id'])) {
                    throw new ValidationError(['courier' => I18n::t('deliv.a_courier')]);
                }
                $d['stage'] = 'way';
                $d['out_at'] = $now;
                break;
            case 'done':
                $d['stage'] = 'done';
                $d['done_at'] = $now;
                break;
            default:
                throw new \InvalidArgumentException('stage');
        }
        Db::save('orders', ['id' => $orderId, 'delivery' => $d]);
        if ($to === 'way' && $o['channel'] === 'delivery') {
            Tickets::courier($orderId);
        }
    }

    public static function assign(string $orderId, ?string $courierId): void
    {
        $o = Orders::editable($orderId);
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
            $c['today'] = (int) Db::value("SELECT COUNT(*) FROM orders WHERE channel = 'delivery' AND day = ? AND status <> 'void' AND deleted = 0 AND json_extract(delivery, '$.courier_id') = ?", [$day, $c['id']]);
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
