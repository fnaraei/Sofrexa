<?php
/**
 * The fifth audit (2026-09-28, C01–C03), and the reason there was a fifth: each audit found a new combination of steps
 * the tests had not tried. So besides the three findings, this file plays long random sequences of kitchen and bill
 * steps and checks, after every single step, the rules that must always hold:
 *   - the ready alert of any bill — open, paid or cancelled — lists exactly its plates that are ready and called, with
 *     their counts now, and no alert lists a plate that is on another bill;
 *   - every other alert about a bill ends when the bill does (paid, cancelled, merged away);
 *   - an alert shows where the bill is now (a bill moved to another table takes its alerts along);
 *   - an alert for one person goes to the bill's waiter, on shift; what the till was asked stays with the till;
 *   - a split or a merge leaves the open bills owing exactly what they owed, and the money on every bill adds up.
 * The sixth audit (D01–D03) found steps the generator did not play: moving a table, cancelling a whole bill, take-away
 * bills. It plays them now, with waiter changes, shifts ending, bill requests and waiter calls.
 * The seventh (E01–E04) found the delivery flow missing: phone deliveries with their fee, the board's "Hazır", out with
 * the courier, delivered, the courier's money — paid before or after. The rules: a bag never leaves with a dish still
 * cooking; a bag on the board is one not both paid and handed over; the guest never reads "delivered" before it is.
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Migrator, Settings, Uuid};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Accounts, Delivery, Notify, Orders, Shifts};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Setup\Seed;

$setup = static function (int $tables = 2): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$boss]));
    $kitchen = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $bar = Db::save('categories', ['names' => ['tr' => 'Bar'], 'station' => 'bar', 'vat_rate' => 10]);
    $items = [
        Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $kitchen, 'price' => '100', 'available' => 1]),
        Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $kitchen, 'price' => '90', 'available' => 1]),
        Menu::saveItem(['names' => ['tr' => 'Çorba'], 'category_id' => $kitchen, 'price' => '35', 'available' => 1]),
        Menu::saveItem(['names' => ['tr' => 'Limonata'], 'category_id' => $bar, 'price' => '40', 'available' => 1]),
    ];
    $area = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $t = [];
    for ($i = 0; $i < $tables; $i++) {
        $t[] = Floor::addTable($area);
    }
    Shifts::open(['TRY' => 0]);
    return ['boss' => $boss, 'items' => $items, 'tables' => $t];
};
/** A fresh database inside one test (the runner gives each test one; a test that loops needs one per round). */
$fresh = static function (): string {
    $db = App::config('storage') . '/' . bin2hex(random_bytes(4)) . '.sqlite';
    Db::disconnect();
    App::setConfig('db', $db);
    Settings::flush();
    Clock::freeze(null);
    Auth::actAs(null);
    Migrator::run();
    return $db;
};
$drop = static function (string $db): void {
    Db::disconnect();
    foreach (glob($db . '*') ?: [] as $f) {
        @unlink($f);
    }
};
$alertOf = static fn(string $orderId): ?array => ($b = Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL AND deleted = 0", [$orderId])) ? json_arr((string) $b) : null;

/** The rules that must hold after every step; returns what is wrong (empty when all is well). */
$broken = static function () use ($alertOf): array {
    $wrong = [];
    // every bill, the ended ones too: a cancelled bill's plates must stop calling, a paid take-away's bag may still call
    foreach (Db::rows('SELECT id FROM orders WHERE deleted = 0') as $o) {
        $called = Db::rows("SELECT id, qty FROM order_items WHERE order_id = ? AND status = 'ready' AND called_at IS NOT NULL AND deleted = 0", [$o['id']]);
        $want = array_column($called, 'id');
        sort($want);
        $alert = $alertOf($o['id']);
        $have = array_map('strval', (array) ($alert['lines'] ?? []));
        sort($have);
        if ($want !== $have) {
            $wrong[] = 'bill ' . substr($o['id'], -4) . ': alert lists ' . count($have) . ' plates, ' . count($want) . ' are called and ready';
            continue;
        }
        $qty = array_column($called, 'qty', 'id');
        foreach ((array) ($alert['items'] ?? []) as $i) {
            if (Orders::qtyText((float) ($qty[$i['id']] ?? -1)) !== (string) $i['qty']) {
                $wrong[] = 'bill ' . substr($o['id'], -4) . ': alert shows ×' . $i['qty'] . ' of a plate that is ×' . Orders::qtyText((float) ($qty[$i['id']] ?? 0));
            }
        }
    }
    foreach (Db::rows("SELECT n.kind FROM notifications n JOIN orders o ON o.id = n.ref_id WHERE n.ref_type = 'order'
        AND n.kind <> 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND o.status NOT IN ('pending', 'open', 'billed')") as $n) {
        $wrong[] = 'an open "' . $n['kind'] . '" alert on a bill that has ended';
    }
    foreach (Db::rows("SELECT * FROM notifications WHERE ref_type IN ('order', 'order_item') AND done_at IS NULL AND deleted = 0") as $n) {
        $o = Db::row('SELECT * FROM orders WHERE id = ' . ($n['ref_type'] === 'order' ? '?' : '(SELECT order_id FROM order_items WHERE id = ?)'), [$n['ref_id']]);
        // where the bill is, worked out here and not by the code under test
        $table = in_array($o['channel'], ['table', 'qr'], true);
        $at = match ($o['channel']) {
            'table', 'qr' => 'Masa ' . Db::value('SELECT number FROM tables WHERE id = ?', [$o['table_id']]),
            'takeaway' => 'Paket #' . $o['no'] . ($o['label'] ? ' · ' . $o['label'] : ''),
            'delivery' => 'Teslimat #' . $o['no'],
            default => 'Online #' . $o['no'],
        };
        $shown = Notify::place($n)['where'] ?? null;
        if ($shown !== $at) {
            $wrong[] = 'a "' . $n['kind'] . '" alert shows ' . $shown . ' for a bill at ' . $at;
        }
        if ($n['user_id'] !== null && ($n['user_id'] !== $o['waiter_id'] || Staff::offShift($n['user_id']))) {
            $wrong[] = 'a "' . $n['kind'] . '" alert goes to someone who is not the bill’s waiter on shift';
        }
        if ($n['kind'] === 'ready' && $n['role'] !== ($table ? ($n['user_id'] ? null : 'waiter') : 'cashier')) {
            $wrong[] = 'the ready alert of a ' . $o['channel'] . ' bill goes to ' . ($n['role'] ?? 'one waiter');
        }
        if ($n['kind'] === 'bill' && isset(json_arr($n['body'])['by']) && ($n['role'] !== 'cashier' || $n['user_id'] !== null)) {
            $wrong[] = 'the till’s bill request went to a waiter';
        }
    }
    // the delivery board (audit 7): worked out here from the rows, not by the code under test
    $board = array_column(Delivery::open(), 'id');
    foreach (Db::rows("SELECT * FROM orders WHERE channel IN ('delivery', 'takeaway', 'online') AND delivery IS NOT NULL AND deleted = 0") as $o) {
        $d = json_arr((string) $o['delivery']);
        $cooking = (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND deleted = 0 AND status IN ('new', 'sent')", [$o['id']]);
        if (in_array($d['stage'] ?? '', ['way', 'done'], true) && $cooking > 0) {
            $wrong[] = 'bag ' . substr($o['id'], -4) . ' left with a dish still cooking';
        }
        $prep = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(status = 'served'), 0) AS served FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void' AND NOT (status = 'served' AND sent_at IS NULL)", [$o['id']]);
        $handed = ($d['stage'] ?? '') === 'done' || ($o['channel'] !== 'delivery' && ($d['type'] ?? '') !== 'delivery' && (int) $prep['n'] > 0 && (int) $prep['served'] >= (int) $prep['n']);
        $should = in_array($o['status'], ['pending', 'open', 'billed'], true) || ($o['status'] === 'paid' && !$handed);
        if ($should !== in_array($o['id'], $board, true)) {
            $wrong[] = 'bag ' . substr($o['id'], -4) . ' (' . $o['status'] . ', ' . ($d['stage'] ?? '-') . ') is ' . ($should ? 'missing from' : 'still on') . ' the board';
        }
        if (\Sofrexa\Modules\Online\OnlineOrders::track(Orders::get($o['id']))['stage'] === 'done' && !$handed) {
            $wrong[] = 'bag ' . substr($o['id'], -4) . ' reads "delivered" before it is';
        }
    }
    // money: what a bill says it was paid is its payments; a closed bill was paid exactly, and sent everything it had
    foreach (Db::rows('SELECT id, status, total, paid, subtotal, discount FROM orders WHERE deleted = 0') as $o) {
        $payments = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ?', [$o['id']]);
        if ($payments !== (int) $o['paid']) {
            $wrong[] = 'bill ' . substr($o['id'], -4) . ': paid ' . $o['paid'] . ' but its payments come to ' . $payments;
        }
        if ((int) $o['total'] !== (int) $o['subtotal'] - (int) $o['discount'] || (int) $o['total'] < 0) {
            $wrong[] = 'bill ' . substr($o['id'], -4) . ': total is not the subtotal less the discount';
        }
        if ($o['status'] === 'paid') {
            if ((int) $o['paid'] !== (int) $o['total']) {
                $wrong[] = 'bill ' . substr($o['id'], -4) . ': closed with ' . $o['paid'] . ' paid on a total of ' . $o['total'];
            }
            if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$o['id']])) {
                $wrong[] = 'bill ' . substr($o['id'], -4) . ': closed with a dish never sent to the kitchen';
            }
        }
    }
    return $wrong;
};

return [
    'C01 merging into a bill with an unfinished ticket keeps the called plate’s alert' => function () use ($setup, $alertOf): void {
        $s = $setup();
        [$pizza, $pasta] = $s['items'];
        $from = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        $a = Orders::addItem($from, $pizza);
        Orders::send($from);
        Kitchen::ready($from, 1, 'kitchen');
        $into = Orders::create('table', ['table_id' => $s['tables'][1], 'guests' => 2]);
        $b = Orders::addItem($into, $pasta);
        Orders::send($into);
        same([1, 1], [(int) Orders::line($a)['round'], (int) Orders::line($b)['round']], 'the same round number on both bills');
        Orders::merge($from, $into);
        same(['ready', 'sent'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        same(null, $alertOf($from));
        same([$a], $alertOf($into)['lines'], 'the pizza was called: it still has its alert, now on the merged bill');
    },

    'C02 splitting a plated, uncalled plate off does not call it — on either bill' => function () use ($setup, $alertOf): void {
        $s = $setup();
        [$pizza, $pasta] = $s['items'];
        $o = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        $a = Orders::addItem($o, $pizza);
        $b = Orders::addItem($o, $pasta);
        Orders::send($o);
        Kitchen::toggleLine($a);
        same(['ready', 'sent'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        $child = Orders::split($o, [$a]);
        same([null, null], [$alertOf($o), $alertOf($child)], 'no alert on the first bill, and none on the new one');
        Kitchen::ready($child, 1, 'kitchen');
        same([$a], $alertOf($child)['lines'], '"Hazır" on its ticket calls it');
    },

    'C03 two account payments in the same millisecond: the later one is refunded first, every time' => function () use ($setup, $fresh, $drop): void {
        for ($run = 0; $run < 25; $run++) {
            $db = $run ? $fresh() : null;
            $s = $setup();
            [$pizza, , $soup] = $s['items'];
            Db::save('items', ['id' => $soup, 'price' => 5000]);
            Clock::freeze(1_790_593_200_000);
            $older = Db::save('customers', ['name' => 'Önceki', 'credit_enabled' => 1]);
            $newer = Db::save('customers', ['name' => 'Sonraki', 'credit_enabled' => 1]);
            // ₺200 of pizza and a ₺50 soup; ₺100 and then ₺50 on account; the pizza is cancelled: ₺100 too much
            $o = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
            $la = Orders::addItem($o, $pizza, 2);
            Orders::addItem($o, $soup);
            Orders::send($o);
            Orders::pay($o, [['method' => 'account', 'amount' => 10000]], $older, false, 10000);
            Orders::pay($o, [['method' => 'account', 'amount' => 5000]], $newer, false, 5000);
            same(1, (int) Db::value('SELECT COUNT(DISTINCT at) FROM payments WHERE order_id = ?', [$o]), 'the same millisecond');
            Orders::voidLine($la, 'masadan kalktılar');
            $plan = Orders::refundPlan(Orders::get($o));
            same([$newer, $older], array_keys($plan['account']), 'run ' . $run);
            Orders::refund($o, 'cash', false);
            same([5000, 0], [Accounts::balance($older), Accounts::balance($newer)], 'run ' . $run);
            if ($db) {
                $drop($db);
            }
        }
    },

    'ids made in the same millisecond keep the order they were made in' => function (): void {
        $ids = [];
        for ($i = 0; $i < 20000; $i++) {
            $ids[] = Uuid::v7();
        }
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        same($ids, $sorted);
        same(20000, count(array_unique($ids)));
        check(Uuid::isValid($ids[0]) && $ids[0][14] === '7', 'still a version 7 UUID');
    },

    'random kitchen and bill steps: the rules hold after every one' => function () use ($setup, $broken, $fresh, $drop): void {
        $steps = 0;
        foreach (range(1, (int) (getenv('SOFREXA_FUZZ') ?: 12)) as $seed) {
            $db = $seed > 1 ? $fresh() : null;
            $s = $setup(3);
            $waiters = [Seed::user('Garson A', 'waiter', '2345'), Seed::user('Garson B', 'waiter', '3456')];
            foreach ($waiters as $w) {
                Staff::clockIn($w);
            }
            $courier = Seed::user('Kurye', 'courier', '4567');
            \Sofrexa\Core\Settings::set('online.delivery_fee', 2500);
            mt_srand($seed);
            $orders = [];
            foreach ($s['tables'] as $t) {
                $orders[] = Orders::create('table', ['table_id' => $t, 'guests' => 2]);
            }
            $pick = static fn(array $a) => $a ? $a[mt_rand(0, count($a) - 1)] : null;
            $phone = static fn(string $type): string => Delivery::create(['type' => $type, 'phone' => '0533 000 00 0' . mt_rand(0, 9), 'name' => 'Müşteri', 'address' => 'Adres',
                'courier_id' => mt_rand(0, 1) ? $courier : '', 'pay' => mt_rand(0, 1) ? 'cash' : 'card', 'items' => [['item_id' => $pick($s['items']), 'qty' => mt_rand(1, 2)]]]);
            for ($n = 0; $n < 150; $n++) {
                $orders = array_column(Db::rows("SELECT id FROM orders WHERE status IN ('open', 'billed') AND deleted = 0"), 'id');
                if (count($orders) < 2) {
                    // bills get paid, cancelled and merged away: new guests sit down (or take away, or phone) so the steps go on
                    $orders[] = match (mt_rand(0, 5)) {
                        0 => Orders::create('takeaway', ['label' => 'Paket']),
                        1 => $phone(mt_rand(0, 2) ? 'delivery' : 'pickup'),
                        default => Orders::create('table', ['table_id' => $pick($s['tables']), 'guests' => 2]),
                    };
                }
                $o = $pick($orders);
                $lines = Db::rows("SELECT id, status, round, station, qty FROM order_items WHERE order_id = ? AND deleted = 0 AND status <> 'void'", [$o]);
                $live = array_values(array_filter($lines, static fn(array $l): bool => in_array($l['status'], ['sent', 'ready'], true)));
                $l = $pick($live);
                $step = mt_rand(0, 28);
                $bill = Db::row('SELECT total, paid FROM orders WHERE id = ?', [$o]);
                $due = (int) $bill['total'] - (int) $bill['paid'];
                $sum = (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ('open', 'billed') AND deleted = 0");
                try {
                    switch ($step) {
                        case 0: case 1:
                            Orders::addItem($o, $pick($s['items']), [1, 1, 2, 3][mt_rand(0, 3)]);
                            break;
                        case 2:
                            Orders::send($o);
                            break;
                        case 3:
                            if ($l) {
                                Kitchen::toggleLine($l['id']);
                            }
                            break;
                        case 4: case 5:
                            if ($l) {
                                Kitchen::ready($o, (int) $l['round'], $l['station']);
                            }
                            break;
                        case 6:
                            if ($l) {
                                Kitchen::callAgain($o, (int) $l['round'], $l['station']);
                            }
                            break;
                        case 7:
                            if ($l) {
                                Kitchen::recall($o, (int) $l['round'], $l['station']);
                            }
                            break;
                        case 8:
                            if ($l) {
                                Orders::voidLine($l['id'], 'test', (float) $l['qty'] > 1 && mt_rand(0, 1) ? 1.0 : 0.0);
                            }
                            break;
                        case 9:
                            $ids = array_column($lines, 'id');
                            if (count($ids) > 1) {
                                shuffle($ids);
                                Orders::split($o, array_slice($ids, 0, mt_rand(1, count($ids) - 1)));
                                same($sum, (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ('open', 'billed') AND deleted = 0"), 'a split keeps what is owed');
                            }
                            break;
                        case 10:
                            $other = $pick(array_values(array_diff($orders, [$o])));
                            if ($other) {
                                Orders::merge($o, $other);
                                same($sum, (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ('open', 'billed') AND deleted = 0"), 'a merge keeps what is owed');
                            }
                            break;
                        case 11: case 12:
                            // "Aldım" on the alert as the phone last showed it
                            $n2 = Db::row("SELECT * FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$o]);
                            if ($n2) {
                                $seen = (array) (json_arr($n2['body'])['lines'] ?? []);
                                if ($seen && mt_rand(0, 1)) {
                                    array_pop($seen);
                                }
                                Kitchen::served($o, Notify::collect($n2, $seen));
                            }
                            break;
                        case 13:
                            if ($l) {
                                Kitchen::start($o, (int) $l['round'], $l['station']);
                            }
                            break;
                        case 14:
                            // part of the bill by card
                            if ($due >= 200) {
                                $part = mt_rand(1, intdiv($due, 100) - 1) * 100;
                                Orders::pay($o, [['method' => 'card', 'amount' => $part]], null, false, $part);
                            }
                            break;
                        case 15:
                            Orders::discount($o, mt_rand(0, 1) ? 'pct' : 'amount', mt_rand(0, 1) ? mt_rand(5, 30) : mt_rand(1, 40) * 100);
                            break;
                        case 16:
                            // the rest, and the bill closes (or it is refused: overpaid, or nothing on it)
                            Orders::pay($o, $due > 0 ? [['method' => 'cash', 'amount' => $due]] : [], null, false);
                            break;
                        case 17:
                            if ($due < 0) {
                                Orders::refund($o, mt_rand(0, 1) ? 'cash' : 'card', false);
                            }
                            break;
                        case 18:
                            if ($l && $l['status'] === 'ready') {
                                Kitchen::served($o, [$l['id']]);
                            }
                            break;
                        case 19:
                            Orders::moveTable($o, $pick($s['tables'])); // refused for a take-away, or a busy table
                            break;
                        case 20:
                            Orders::void($o, 'test'); // the whole bill (refused once something was paid)
                            break;
                        case 21:
                            Orders::setWaiter($o, $pick($waiters));
                            break;
                        case 22:
                            // a waiter's shift ends, or starts again
                            $w = $pick($waiters);
                            Staff::offShift($w) ? Staff::clockIn($w) : Staff::clockOut($w);
                            break;
                        case 23:
                            // the waiter asks the till for the bill (as the table screen does)
                            Notify::push('bill', ['where' => Orders::where(Orders::get($o)), 'by' => 'Patron'], null, 'cashier', $o);
                            break;
                        case 24:
                            // the guests call their waiter
                            $bill2 = Orders::get($o);
                            if (in_array($bill2['channel'], ['table', 'qr'], true)) {
                                Notify::toWaiter('call', $bill2, ['qr' => true]);
                            }
                            break;
                        case 25:
                            // a phone order comes in
                            $phone(mt_rand(0, 2) ? 'delivery' : 'pickup');
                            break;
                        case 26: case 27:
                            // a bag on the board moves on — paid or not (refused: still cooking, no courier, …)
                            if ($bag = $pick(array_column(Delivery::open(), 'id'))) {
                                Delivery::move($bag, ['ready', 'way', 'way', 'done'][mt_rand(0, 3)]);
                            }
                            break;
                        case 28:
                            // a courier is given, or hands in the money of what they delivered
                            if (mt_rand(0, 1) && ($bag = $pick(array_column(Delivery::open(), 'id')))) {
                                Delivery::assign($bag, $courier);
                            } else {
                                Delivery::settle($courier);
                            }
                            break;
                    }
                } catch (\InvalidArgumentException | \Sofrexa\Core\ValidationError | \Sofrexa\Core\HttpError) {
                    // a step the rules refuse (nothing to split, a plate already cancelled…) is fine; it must change nothing
                }
                $steps++;
                $wrong = $broken();
                if ($wrong) {
                    throw new RuntimeException("seed $seed, step $n (kind $step): " . implode('; ', $wrong));
                }
            }
            if ($db) {
                $drop($db);
            }
        }
        check($steps > 1000, 'enough steps were played (' . $steps . ')');
    },
];
