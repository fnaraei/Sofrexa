<?php
/** Orders end to end: table order, options, send, void, discount, pre-bill, mixed-currency payment, shift and Z report. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db, Settings};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\Modules\Orders\{Delivery, Notify, Orders, Rates, Shifts};
use Sofrexa\Print\Printer;
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $uid = Seed::user('Can Kasa', 'manager', '4821');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
    Settings::set('currency.accepted', ['GBP', 'USD', 'EUR']);
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $bar = Db::save('categories', ['names' => ['tr' => 'İçecekler'], 'station' => 'bar', 'vat_rate' => 10]);
    $adana = Menu::saveItem(['names' => ['tr' => 'Adana Kebap'], 'category_id' => $cat, 'price' => '870', 'available' => 1, 'show_web' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $ayran = Menu::saveItem(['names' => ['tr' => 'Ayran'], 'category_id' => $bar, 'price' => '50', 'available' => 1, 'show_web' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Bahçe']]);
    $table = Floor::addTable($area);
    return compact('adana', 'ayran', 'table');
};

return [
    'table order: add, merge same lines, send to kitchen and bar' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        Clock::freeze(strtotime('2026-09-28 20:14') * 1000);
        $o = Orders::forTable($table, 4);
        same($o, Orders::forTable($table), 'one open order per table');
        $l1 = Orders::addItem($o, $adana, 1);
        same($l1, Orders::addItem($o, $adana, 1), 'same item again raises the quantity');
        Orders::addItem($o, $ayran, 4);
        same(174000 + 20000, (int) Db::value('SELECT total FROM orders WHERE id = ?', [$o]));
        same(2, Orders::send($o));
        same(0, (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status = 'new'", [$o]));
        $jobs = Db::pairs('SELECT kind, COUNT(*) FROM print_jobs GROUP BY kind');
        same(1, (int) ($jobs['kitchen'] ?? 0), 'kitchen ticket');
        same(1, (int) ($jobs['bar'] ?? 0), 'bar ticket');
        $kitchen = Printer::preview(base64_decode((string) Db::value("SELECT payload FROM print_jobs WHERE kind = 'kitchen'")));
        check(str_contains($kitchen, 'MASA 1') && str_contains($kitchen, '2× ADANA KEBAP'), 'kitchen ticket content: ' . $kitchen);
    },

    'void a sent line with a reason; the total drops and the log records it' => function () use ($setup): void {
        ['adana' => $adana, 'table' => $table] = $setup();
        $o = Orders::forTable($table);
        $l = Orders::addItem($o, $adana, 2);
        Orders::send($o);
        try {
            Orders::voidLine($l, '');
            throw new LogicException('void without reason');
        } catch (InvalidArgumentException) {
        }
        Orders::voidLine($l, 'müşteri vazgeçti', 1);
        same(87000, (int) Db::value('SELECT total FROM orders WHERE id = ?', [$o]));
        check((bool) Db::value("SELECT 1 FROM audit_log WHERE action = 'order.void_item' AND summary LIKE '%Adana Kebap ×1 · ₺870 · sebep: müşteri vazgeçti%'"), 'audit');
        same(1, (int) Db::value("SELECT COUNT(*) FROM print_jobs WHERE kind = 'void'"), 'cancel ticket');
    },

    'pay: foreign cash at the hand-entered rate + card, change in lira, receipt, shift and Z' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        Shifts::open(['TRY' => 50000]);
        Rates::set('GBP', 42.80);
        $o = Orders::forTable($table, 2);
        Orders::addItem($o, $adana, 2);   // 1.740
        Orders::addItem($o, $ayran, 2);   //   100
        Orders::discount($o, 'pct', 10, 'müdavim'); // −184 → 1.656
        same(165600, (int) Db::value('SELECT total FROM orders WHERE id = ?', [$o]));
        Orders::preBill($o);
        same('billed', Db::value('SELECT status FROM orders WHERE id = ?', [$o]));
        $r = Orders::pay($o, [['method' => 'cash', 'currency' => 'GBP', 'amount_fx' => 30], ['method' => 'card', 'amount' => 40000]]);
        // £30 × 42,80 = ₺1.284 + ₺400 card = ₺1.684 → change ₺28
        same(2800, $r['change']);
        check($r['paid'], 'closed');
        same('paid', Db::value('SELECT status FROM orders WHERE id = ?', [$o]));
        $receipt = Printer::preview(base64_decode((string) Db::value("SELECT payload FROM print_jobs WHERE kind = 'receipt'")));
        check(str_contains($receipt, 'TOPLAM') && str_contains($receipt, '1.656 TL') && str_contains($receipt, 'Para üstü (TL)'), 'receipt: ' . $receipt);
        $sum = Shifts::summary(Shifts::currentId());
        same(50000 - 2800, (int) $sum['cash']['TRY'], 'drawer: opening − change');
        same(30.0, (float) $sum['cash']['GBP'], 'drawer: £30');
        Shifts::move('out', 'TRY', 10000, 'Et Dünyası', 'fatura #4471');
        $z = Shifts::close(['TRY' => 37200, 'GBP' => 30]);
        same(1, $z);
        $zText = Printer::preview(base64_decode((string) Db::value("SELECT payload FROM print_jobs WHERE kind = 'z'")));
        check(str_contains($zText, 'GÜN SONU · Z #0001') && str_contains($zText, 'Kasa beklenen TL'), 'Z report');
    },

    'pay by guest: a share at a time, each guest gets their own change' => function () use ($setup): void {
        ['adana' => $adana, 'table' => $table] = $setup();
        Shifts::open(['TRY' => 0]);
        $o = Orders::forTable($table, 3);
        Orders::addItem($o, $adana, 3); // 2.610 → 870 each
        $r = Orders::pay($o, [['method' => 'cash', 'currency' => 'TRY', 'amount' => 100000]], null, false, 87000);
        same(13000, $r['change'], 'change of the first guest');
        check(!$r['paid'], 'still open');
        same(174000, $r['due']);
        Orders::pay($o, [['method' => 'card', 'amount' => 87000]], null, false, 87000);
        $r = Orders::pay($o, [['method' => 'cash', 'currency' => 'TRY', 'amount' => 87000]], null, false, 87000);
        check($r['paid'], 'closed after the last share');
    },

    'foreign cash of exactly the shown amount closes the bill (cent rounding)' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        Shifts::open(['TRY' => 0]);
        Rates::set('GBP', 42.80);
        $o = Orders::forTable($table);
        Orders::addItem($o, $adana, 2);
        Orders::addItem($o, $ayran, 2);
        Orders::discount($o, 'amount', 26400); // 1.840 − 264 = 1.576 → £36,82 (36,8224)
        $r = Orders::pay($o, [['method' => 'cash', 'currency' => 'GBP', 'amount_fx' => 36.82]]);
        check($r['paid'], 'paid');
        same(0, $r['change']);
        same(157600, (int) Db::value('SELECT SUM(amount) FROM payments WHERE order_id = ?', [$o]));
    },

    'phone delivery: new customer and address, courier out and back, settled into the shift' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran] = $setup();
        Shifts::open(['TRY' => 10000]);
        $courier = Seed::user('Emre Kurye', 'courier', '6060');
        $id = Delivery::create(['type' => 'delivery', 'phone' => '0533 412 77 90', 'name' => 'Ahmet Yılmaz', 'address' => 'Levent Mah. 12. Sk. No 4, Girne', 'address_label' => 'Ev',
            'courier_id' => $courier, 'pay' => 'cash', 'cash_given' => '2000', 'note' => 'Zil çalışmıyor', 'items' => [['item_id' => $adana, 'qty' => 1], ['item_id' => $ayran, 'qty' => 2]]]);
        $o = Orders::get($id);
        same('delivery', $o['channel']);
        same(97000, (int) $o['total']);
        same('Levent Mah. 12. Sk. No 4, Girne', $o['delivery']['address']);
        check((bool) Customers::byPhone('+90 533 412 7790'), 'customer found by the normalised phone');
        same(0, (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status = 'new'", [$id]), 'sent to the kitchen');
        same(1, (int) Db::value("SELECT COUNT(*) FROM print_jobs WHERE kind = 'courier'"), 'courier slip');
        Delivery::move($id, 'ready'); // the kitchen's "Hazır" for its tickets
        Delivery::move($id, 'way');
        Delivery::move($id, 'done');
        $c = array_values(array_filter(Delivery::couriers(), static fn(array $x): bool => $x['id'] === $courier))[0];
        same('₺970', Delivery::cashText($c['cash']));
        same(1, Delivery::settle($courier));
        same('paid', Db::value('SELECT status FROM orders WHERE id = ?', [$id]));
        same($courier, Db::value('SELECT courier_id FROM payments WHERE order_id = ?', [$id]));
        $sum = Shifts::summary(Shifts::currentId());
        same(10000 + 97000, (int) $sum['cash']['TRY'], 'courier cash is in the drawer');
    },

    'shift totals count bills paid in the shift even when opened before it' => function () use ($setup): void {
        ['adana' => $adana, 'table' => $table] = $setup();
        $o = Orders::forTable($table);
        Orders::addItem($o, $adana, 1);
        Shifts::open(['TRY' => 0]);
        Orders::pay($o, [['method' => 'card', 'amount' => 87000]]);
        $sum = Shifts::summary(Shifts::currentId());
        same(87000, (int) $sum['orders']['total']);
        same(1, (int) $sum['orders']['n']);
    },

    'notifications: the till hears "ask for the bill", handled ones stop counting' => function () use ($setup): void {
        ['adana' => $adana, 'table' => $table] = $setup();
        $o = Orders::forTable($table);
        Orders::addItem($o, $adana, 1);
        $n = Notify::push('bill', ['where' => 'Masa 1', 'by' => 'Ayşe'], null, 'cashier', $o);
        same($n, Notify::push('bill', ['where' => 'Masa 1', 'by' => 'Ayşe'], null, 'cashier', $o), 'one open alert per order and kind');
        same(1, Notify::unread(), 'the manager hears the till');
        Notify::done($n);
        same(0, Notify::unread());
    },

    'split: pay part of a table separately' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        Shifts::open(['TRY' => 0]); // a card payment goes through the open shift
        $o = Orders::forTable($table);
        $a = Orders::addItem($o, $adana, 1);
        Orders::addItem($o, $ayran, 1);
        $part = Orders::split($o, [$a]);
        same(87000, (int) Db::value('SELECT total FROM orders WHERE id = ?', [$part]));
        same(5000, (int) Db::value('SELECT total FROM orders WHERE id = ?', [$o]));
        Orders::pay($part, [['method' => 'card', 'amount' => 87000]], null, false);
        same('open', Db::value('SELECT status FROM orders WHERE id = ?', [$o]), 'the rest stays open');
    },
];
