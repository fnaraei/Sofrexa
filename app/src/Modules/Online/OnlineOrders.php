<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Online;

use Sofrexa\Core\{App, Audit, Clock, Db, I18n, Mailer, Money, RateLimit, Settings, ValidationError};
use Sofrexa\Modules\Orders\{Delivery, Notify, Orders};
use Sofrexa\Modules\QrOrder\QrOrders;

/**
 * Online orders for delivery or pickup (PLAN §5.11, Figma O3–O6).
 *
 * Signed-in, verified customers order on the web copy; the order waits (status 'pending', channel 'online') until
 * the till accepts it and says when it will be ready. Payment is only at the door or in the restaurant (cash or card).
 * The order's 'delivery' JSON keeps: online, type (delivery | pickup), phone, address, address_id, pay_hint (cash | card),
 * cash_given, note, when ('asap' or 'HH:MM'), eta (the "35–45" window), eta_at (set on approval), lang, stage…
 * E-mails about the order's progress go out from the copy that faces the internet (the web copy, or a lone PC).
 */
final class OnlineOrders
{
    public const MAX_LINES = 40;
    public const MAX_QTY = 20;

    /** open · off (switched off) · closed (outside the online hours) · offline (the web copy lost the till). */
    public static function availability(): string
    {
        if (!Settings::get('online.enabled', true) || (!Settings::get('online.delivery', true) && !Settings::get('online.pickup', true))) {
            return 'off';
        }
        if (App::isWeb() && \Sofrexa\Sync\Status::get()['state'] === 'offline') {
            return 'offline';
        }
        return self::openNow() ? 'open' : 'closed';
    }

    /** [open, close] minutes after midnight from "11:30 – 22:30", or null when no hours are set. */
    public static function hours(): ?array
    {
        if (!preg_match('/(\d{1,2})[:.](\d{2})\D+(\d{1,2})[:.](\d{2})/', (string) Settings::get('online.hours', ''), $m)) {
            return null;
        }
        return [(int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]];
    }

    private static function minuteNow(): int
    {
        $t = (new \DateTimeImmutable('@' . intdiv(Clock::ms(), 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return (int) $t->format('G') * 60 + (int) $t->format('i');
    }

    public static function openNow(): bool
    {
        $h = self::hours();
        if (!$h) {
            return true;
        }
        $now = self::minuteNow();
        return $h[0] <= $h[1] ? ($now >= $h[0] && $now < $h[1]) : ($now >= $h[0] || $now < $h[1]);
    }

    /** Later times of today for "Saat seç": every 15 minutes from an hour ahead until closing. */
    public static function slots(): array
    {
        $h = self::hours() ?? [0, 24 * 60];
        $close = $h[1] > $h[0] ? $h[1] : 24 * 60;
        $from = (int) (ceil((self::minuteNow() + 60) / 15) * 15);
        $out = [];
        for ($m = max($from, $h[0]); $m <= $close - 15; $m += 15) {
            $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        return $out;
    }

    public static function minOrder(): int
    {
        return (int) Settings::get('online.min_order', 50000);
    }

    public static function fee(): int
    {
        return (int) Settings::get('online.delivery_fee', 0);
    }

    /** "35–45" */
    public static function eta(): string
    {
        return (string) Settings::get('online.eta_minutes', '35–45');
    }

    /** Which of delivery / pickup the restaurant offers. */
    public static function types(): array
    {
        return array_keys(array_filter(['delivery' => (bool) Settings::get('online.delivery', true), 'pickup' => (bool) Settings::get('online.pickup', true)]));
    }

    /** The online menu: dishes shown for online ordering, in the customer's language. */
    public static function menu(): array
    {
        return QrOrders::menu('show_online');
    }

    /**
     * The cart as the server prices it. $cart: [{item, qty, mods: [], note}] from the phone.
     * Returns lines [{item, name, qty, unit, total, photo, mods, note, note_text}] and the subtotal; unavailable dishes are left out.
     */
    public static function priced(array $cart): array
    {
        $items = [];
        foreach (self::menu() as $c) {
            foreach ($c['items'] as $i) {
                $items[$i['id']] = $i;
            }
        }
        $lines = [];
        $sub = 0;
        foreach (array_slice($cart, 0, self::MAX_LINES) as $l) {
            $i = $items[(string) ($l['item'] ?? '')] ?? null;
            $qty = min(self::MAX_QTY, (int) ($l['qty'] ?? 0));
            if (!$i || !$i['orderable'] || $qty < 1) {
                continue;
            }
            $mods = array_values(array_filter(array_map('strval', (array) ($l['mods'] ?? []))));
            $unit = $i['price'];
            $names = [];
            foreach ($i['groups'] as $g) {
                foreach ($g['options'] as $o) {
                    if (in_array($o['id'], $mods, true)) {
                        $unit += $o['price'];
                        $names[] = $o['name'];
                    }
                }
            }
            $note = mb_substr(trim((string) ($l['note'] ?? '')), 0, 120);
            $lines[] = ['item' => $i['id'], 'name' => $i['name'], 'qty' => $qty, 'unit' => $unit, 'total' => $qty * $unit, 'photo' => $i['photo'],
                'mods' => $mods, 'note' => $note, 'note_text' => implode(', ', array_filter([...$names, $note]))];
            $sub += $qty * $unit;
        }
        return ['lines' => $lines, 'subtotal' => $sub, 'count' => array_sum(array_column($lines, 'qty'))];
    }

    /**
     * O3 / O6 "Siparişi ver". $in: type, address_id, when (asap | HH:MM), phone, pay (cash | card), cash_given, note.
     * @throws ValidationError|\InvalidArgumentException with a message for the customer
     */
    public static function place(array $account, array $cart, array $in): string
    {
        if (self::availability() !== 'open') {
            throw new \InvalidArgumentException(I18n::t('on.err_closed'));
        }
        if (!$account['verified_at']) {
            throw new \InvalidArgumentException(I18n::t('on.err_unverified'));
        }
        if (!RateLimit::hit('online:order:' . $account['id'], 5, 600_000)) {
            throw new \InvalidArgumentException(I18n::t('on.err_limit'));
        }
        $p = self::priced($cart);
        if (!$p['lines']) {
            throw new \InvalidArgumentException(I18n::t('on.err_empty'));
        }
        $types = self::types();
        $type = in_array($in['type'] ?? '', $types, true) ? $in['type'] : ($types[0] ?? 'delivery');
        $err = [];
        $address = '';
        $addressId = null;
        if ($type === 'delivery') {
            $addr = Db::row('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ? AND deleted = 0', [(string) ($in['address_id'] ?? ''), $account['customer_id']]);
            if (!$addr) {
                $err['address_id'] = I18n::t('on.err_address');
            } else {
                $address = (string) $addr['address'];
                $addressId = $addr['id'];
            }
            if ($p['subtotal'] < self::minOrder()) {
                throw new \InvalidArgumentException(I18n::t('on.min_more', ['amount' => money(self::minOrder() - $p['subtotal'])]));
            }
        }
        $phone = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40);
        if (strlen(phone_norm($phone)) < 7) {
            $err['phone'] = I18n::t('on.err_phone');
        }
        $when = (string) ($in['when'] ?? 'asap');
        if ($when !== 'asap' && !in_array($when, self::slots(), true)) {
            $err['when'] = I18n::t('on.err_when');
        }
        $pay = ($in['pay'] ?? 'cash') === 'card' ? 'card' : 'cash';
        $fee = $type === 'delivery' ? self::fee() : 0;
        $total = $p['subtotal'] + $fee;
        $given = $pay === 'cash' ? Money::parse($in['cash_given'] ?? 0) : 0;
        if ($given > 0 && $given < $total) {
            $err['cash_given'] = I18n::t('on.err_cash');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        $orderId = Db::tx(static function () use ($account, $p, $type, $address, $addressId, $phone, $when, $pay, $given, $fee, $in): string {
            $id = Orders::create('online', [
                'status' => 'pending',
                'customer_id' => $account['customer_id'],
                'waiter_id' => null,
                'label' => $account['name'],
                'delivery' => [
                    'online' => true, 'type' => $type, 'phone' => $phone, 'address' => $address, 'address_id' => $addressId,
                    'pay_hint' => $pay, 'cash_given' => $given, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200),
                    'when' => $when, 'pickup_at' => $when !== 'asap' ? $when : null, 'eta' => $type === 'delivery' ? self::eta() : null,
                    'stage' => 'kitchen', 'lang' => I18n::lang(), 'account_id' => $account['id'],
                ],
            ]);
            foreach ($p['lines'] as $l) {
                Orders::addItem($id, $l['item'], $l['qty'], $l['mods'], $l['note']);
            }
            if ($fee > 0) {
                // the delivery fee is a line of its own that never goes to the kitchen
                Db::save('order_items', ['order_id' => $id, 'name' => 'Teslimat ücreti', 'qty' => 1, 'unit_price' => $fee, 'station' => 'kitchen',
                    'status' => 'served', 'created_at' => Clock::ms(), 'served_at' => Clock::ms()]);
                Orders::recalc($id);
            }
            return $id;
        });
        $o = Orders::get($orderId);
        Audit::log('order.online', Orders::where($o) . ' · ' . $account['name'] . ' · ' . Money::fmt((int) $o['total'], false, 'tr'), 'order', $orderId, [], ['id' => null, 'name' => 'Online']);
        if (QrOrders::owner()) {
            self::intake();
        }
        self::notices();
        return $orderId;
    }

    /** On the till: a new online order is announced to the cashiers once. */
    public static function intake(): int
    {
        if (!QrOrders::owner()) {
            return 0;
        }
        $n = 0;
        foreach (Db::rows("SELECT * FROM orders WHERE channel = 'online' AND status = 'pending' AND intake_at IS NULL AND deleted = 0 ORDER BY opened_at, rowid") as $o) {
            Db::save('orders', ['id' => $o['id'], 'intake_at' => Clock::ms()]);
            $d = json_arr($o['delivery']);
            Notify::push('online', ['where' => Orders::where($o), 'what' => I18n::t(($d['type'] ?? 'delivery') === 'pickup' ? 'on.type_pickup' : 'on.type_delivery', [], 'tr')
                . ' · ' . Money::fmt((int) $o['total'], false, 'tr')], null, 'cashier', $o['id']);
            $n++;
        }
        return $n;
    }

    /** "Onayla": the till accepts the order and says in how many minutes it will be ready (delivery: arrive). */
    public static function approve(string $orderId, int $minutes): void
    {
        $o = Orders::editable($orderId);
        if ($o['channel'] !== 'online' || $o['status'] !== 'pending') {
            throw new \InvalidArgumentException(I18n::t('qr.err_handled'));
        }
        $minutes = max(5, min(240, $minutes ?: self::etaMid()));
        $d = $o['delivery'];
        $d['eta_at'] = Clock::ms() + $minutes * 60_000;
        $d['approved_at'] = Clock::ms();
        Db::save('orders', ['id' => $orderId, 'delivery' => $d, 'approved_at' => Clock::ms()]);
        Delivery::move($orderId, 'approve');
        Notify::closeFor($orderId, 'online');
        if (($d['type'] ?? 'delivery') === 'delivery') {
            \Sofrexa\Modules\Orders\Tickets::courier($orderId);
        }
        self::notices();
    }

    /** "Reddet": the order is cancelled before anything was cooked; the customer gets an e-mail. */
    public static function reject(string $orderId, string $reason = ''): void
    {
        $o = Orders::editable($orderId);
        if ($o['channel'] !== 'online' || $o['status'] !== 'pending') {
            throw new \InvalidArgumentException(I18n::t('qr.err_handled'));
        }
        Db::save('orders', ['id' => $orderId, 'status' => 'void', 'closed_at' => Clock::ms(), 'note' => trim('iptal: ' . ($reason ?: 'reddedildi'))]);
        Notify::closeFor($orderId);
        Audit::log('order.online_reject', Orders::where($o) . ' · ' . ($reason ?: '—'), 'order', $orderId);
        self::notices();
    }

    /** Middle of the "35–45" window in minutes (40). */
    public static function etaMid(): int
    {
        preg_match_all('/\d+/', self::eta(), $m);
        $n = array_map('intval', $m[0]);
        return $n ? (int) round(array_sum($n) / count($n)) : 40;
    }

    /**
     * What the customer sees (O4): stage key, hero [icon, title, detail], steps [[state, icon, label, ms|null]].
     * Delivery: alındı → onaylandı → hazırlandı → yolda → teslim edildi. Pickup: alındı → onaylandı → hazırlanıyor → hazır → teslim alındı.
     */
    public static function track(array $o): array
    {
        $d = is_array($o['delivery']) ? $o['delivery'] : json_arr($o['delivery']);
        $pickup = ($d['type'] ?? 'delivery') === 'pickup';
        $lines = array_filter($o['lines'] ?? [], static fn(array $l): bool => $l['status'] !== 'void' && $l['item_id']);
        $readyAt = $d['ready_at'] ?? null;
        if (!$readyAt && $lines && !array_filter($lines, static fn(array $l): bool => !in_array($l['status'], ['ready', 'served'], true))) {
            $readyAt = max(array_map(static fn(array $l): int => (int) ($l['ready_at'] ?: $l['served_at']), $lines)) ?: null;
        }
        $doneAt = $d['done_at'] ?? ($o['status'] === 'paid' ? $o['closed_at'] : null);
        $stage = match (true) {
            $o['status'] === 'void' => 'cancelled',
            $o['status'] === 'pending' => 'received',
            (bool) $doneAt => 'done',
            !$pickup && ($d['stage'] ?? '') === 'way' => 'way',
            (bool) $readyAt => 'ready',
            default => 'kitchen',
        };
        // position on the way: received 0 · kitchen 1 · ready 2 · way 3 · done 4
        $pos = ['received' => 0, 'kitchen' => 1, 'ready' => 2, 'way' => 3, 'done' => 4][$stage] ?? -1;
        $st = static fn(int $at): string => $pos > $at ? 'done' : ($pos === $at ? 'current' : 'pending');
        $steps = [
            ['done', 'check', I18n::t('on.st_received'), (int) $o['opened_at']],
            [$pos >= 1 ? 'done' : 'current', 'check', I18n::t($pos >= 1 ? 'on.st_approved' : 'on.st_approving'), $pos >= 1 ? ($d['approved_at'] ?? $o['approved_at'] ?? null) : null],
        ];
        if ($pickup) {
            $steps[] = [$st(1), 'chef-hat', I18n::t('on.st_cooking'), null];
            $steps[] = [$pos === 4 ? 'done' : ($pos >= 2 ? 'current' : 'pending'), 'bag', I18n::t('on.st_ready_pickup'), $readyAt];
            $steps[] = [$pos === 4 ? 'done' : 'pending', 'check-circle', I18n::t('on.st_picked'), $doneAt];
        } else {
            $steps[] = [$st(1), 'chef-hat', I18n::t($pos >= 2 ? 'on.st_ready' : 'on.st_cooking'), $pos >= 2 ? $readyAt : null];
            $steps[] = [$st(3), 'bike', I18n::t('on.st_way'), $pos >= 3 ? ($d['out_at'] ?? null) : null];
            $steps[] = [$pos === 4 ? 'done' : 'pending', 'home', I18n::t('on.st_done'), $doneAt];
        }
        if ($stage === 'cancelled') {
            $steps = [$steps[0], ['cancelled', 'close', I18n::t('on.st_cancelled'), $o['closed_at'] ? (int) $o['closed_at'] : null]];
        }
        $eta = !empty($d['eta_at']) ? date('H:i', intdiv((int) $d['eta_at'], 1000)) : null;
        $courier = !empty($d['courier_id']) ? first_name((string) Db::value('SELECT name FROM users WHERE id = ?', [$d['courier_id']])) : '';
        $hero = match ($stage) {
            'received' => ['clock', I18n::t('on.h_received'), I18n::t('on.h_received_d')],
            'kitchen' => ['chef-hat', I18n::t('on.h_kitchen'), $eta ? I18n::t($pickup ? 'on.h_ready_at' : 'on.h_arrive_at', ['t' => $eta]) : ''],
            'ready' => ['bag', I18n::t($pickup ? 'on.h_ready_pickup' : 'on.h_ready'), $pickup ? (string) Settings::get('profile.address', '') : I18n::t('on.h_ready_d')],
            'way' => ['bike', I18n::t('on.h_way'), trim(($eta ? I18n::t('on.h_arrive_at', ['t' => $eta]) : '') . ($courier !== '' ? ' · ' . $courier : ''), ' ·')],
            'done' => ['home', I18n::t($pickup ? 'on.h_picked' : 'on.h_done'), I18n::t('on.h_done_d')],
            default => ['x-circle', I18n::t('on.h_cancelled'), I18n::t('on.h_cancelled_d')],
        };
        return ['stage' => $stage, 'pickup' => $pickup, 'hero' => $hero, 'steps' => $steps, 'eta' => $eta];
    }

    /** The customer's recent online orders, newest first. */
    public static function recent(string $customerId, int $limit = 10): array
    {
        return Db::rows("SELECT * FROM orders WHERE customer_id = ? AND channel = 'online' AND deleted = 0 ORDER BY opened_at DESC LIMIT $limit", [$customerId]);
    }

    // ------------------------------------------------------------ e-mails

    /** This copy's address for links in e-mails (config base_url, else the host of the current request). */
    public static function baseUrl(): string
    {
        $base = rtrim((string) App::config('base_url', ''), '/');
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        }
        return $base;
    }

    /** E-mails go out from the copy that faces the internet: the web copy, or a PC that has no web copy. */
    public static function mails(): bool
    {
        return App::isWeb() || (string) App::config('sync.remote_url', '') === '';
    }

    /**
     * One e-mail per step the customer cares about (received, approved with the time, ready for pickup, on the way,
     * cancelled), each sent once. Runs after changes arrive from the till and after local changes.
     */
    public static function notices(): int
    {
        if (!self::mails()) {
            return 0;
        }
        $n = 0;
        $rows = Db::rows("SELECT o.*, a.email, a.lang AS acc_lang, c.name AS customer_name FROM orders o JOIN online_accounts a ON a.customer_id = o.customer_id AND a.deleted = 0
            JOIN customers c ON c.id = o.customer_id WHERE o.channel = 'online' AND o.deleted = 0 AND o.opened_at > ?", [Clock::ms() - 2 * 86_400_000]);
        foreach ($rows as $o) {
            $d = json_arr($o['delivery']);
            $o['lines'] = Orders::lines($o['id']);
            $t = self::track($o + ['delivery' => $d]);
            $key = match ($t['stage']) {
                'received' => 'received',
                'kitchen' => 'approved',
                'ready' => $t['pickup'] ? 'ready' : 'approved',
                'way' => 'way',
                'cancelled' => 'cancelled',
                default => null,
            };
            if ($key === null || Db::value('SELECT 1 FROM online_mail WHERE order_id = ? AND stage = ?', [$o['id'], $key])) {
                continue;
            }
            Db::exec('INSERT OR IGNORE INTO online_mail (order_id, stage, at) VALUES (?, ?, ?)', [$o['id'], $key, Clock::ms()]);
            $lang = (string) ($d['lang'] ?? $o['acc_lang'] ?? 'tr');
            $no = '#' . sprintf('%04d', (int) $o['no']);
            $shop = (string) Settings::get('profile.name');
            $link = self::baseUrl() . '/online/siparis/' . $o['id'];
            $body = I18n::t('on.mail_' . $key, ['name' => first_name((string) $o['customer_name']), 'no' => $no, 'shop' => $shop, 't' => (string) $t['eta'],
                'total' => Money::fmt((int) $o['total'], false, $lang)], $lang) . "\n\n" . I18n::t('on.mail_follow', ['link' => $link], $lang)
                . "\n\n" . $shop . ((string) Settings::get('profile.phone', '') !== '' ? ' · ' . Settings::get('profile.phone') : '');
            Mailer::send((string) $o['email'], I18n::t('on.mail_subject_' . $key, ['no' => $no, 'shop' => $shop], $lang), $body);
            $n++;
        }
        return $n;
    }
}
