<?php
/**
 * The findings of the second audit (2026-09-28, R01–R13 and the notes after them), each with the result it must have:
 * money that was taken is never swallowed, merges and splits keep what is owed, points come back once, the web copy
 * keeps to the till rule on every door, the ready alert hands over only the plates that were on the screen.
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, HttpError, Router, Settings};
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\Modules\Orders\{Assign, Notify, Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Modules\Reports\{Reports, StockReport};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Setup\Seed;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $a = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1, 'show_qr' => 1]);
    $b = Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $cat, 'price' => '100', 'available' => 1, 'show_qr' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $t1 = Floor::addTable($area);
    $t2 = Floor::addTable($area);
    $t3 = Floor::addTable($area);
    Shifts::open(['TRY' => 0]);
    return compact('boss', 'cat', 'a', 'b', 't1', 't2', 't3');
};
/** A ₺100 pizza (and a ₺100 pasta) on a table's bill: [order, pizza line, pasta line]. */
$bill = static function (array $s, bool $two = false, string $table = 't1'): array {
    $o = Orders::create('table', ['table_id' => $s[$table], 'guests' => 2]);
    $l = Orders::addItem($o, $s['a']);
    $l2 = $two ? Orders::addItem($o, $s['b']) : null;
    return [$o, $l, $l2];
};
$waiter = static function (string $name, array $deny = []): string {
    $u = Seed::user($name, 'waiter', (string) random_int(1000, 9999));
    if ($deny) {
        Db::save('users', ['id' => $u, 'perms_deny' => $deny]);
    }
    Staff::clockIn($u);
    return $u;
};
$day = static fn(string $d): array => Clock::dayRange($d, (int) Settings::get('day.rollover_hour', 5));

return [
    'R01 a discount may not take a bill below what was already paid' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o] = $bill($s);
        Orders::pay($o, [['method' => 'card', 'amount' => 8000]], null, false);
        try {
            Orders::discount($o, 'pct', 50);
            throw new LogicException('discounted below the payment');
        } catch (\Sofrexa\Core\ValidationError) {
        }
        $r = Orders::get($o);
        same([10000, 8000, 'open'], [(int) $r['total'], (int) $r['paid'], $r['status']], 'the bill is as it was');
        Orders::discount($o, 'amount', 2000);
        same(8000, (int) Orders::get($o)['total'], 'down to exactly what was paid is fine');
        same(true, Orders::pay($o, [], null, false)['paid'], 'and it closes without a made-up payment');
    },

    'R01 a dish cancelled after a part payment: the difference is refunded on record, then the bill closes' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, , $pasta] = $bill($s, true);
        Orders::send($o);
        Orders::pay($o, [['method' => 'card', 'amount' => 15000]], null, false);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        same([10000, 15000], [(int) Orders::get($o)['total'], (int) Orders::get($o)['paid']]);
        try {
            Orders::pay($o, [], null, false);
            throw new LogicException('closed an overpaid bill');
        } catch (\InvalidArgumentException) {
        }
        same('open', Orders::get($o)['status'], 'an overpaid bill is not closed by "Ödemeyi tamamla"');
        same(5000, Orders::refund($o, 'cash', false));
        $r = Orders::get($o);
        same(['paid', 10000, 10000], [$r['status'], (int) $r['total'], (int) $r['paid']]);
        $back = Db::row('SELECT method, amount, shift_id FROM payments WHERE order_id = ? AND amount < 0', [$o]);
        same(['cash', -5000, Shifts::currentId()], [$back['method'], (int) $back['amount'], $back['shift_id']], 'one refund, in cash, in this shift');
    },

    'R02 a merge keeps what the two bills owed: a capped discount and a percentage of the target' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, , $pasta] = $bill($s, true);
        Orders::discount($o, 'amount', 15000);
        Orders::updateLine($pasta, 0);
        [$into] = $bill($s, false, 't2');
        $before = (int) Orders::get($o)['total'] + (int) Orders::get($into)['total'];
        Orders::merge($o, $into);
        same($before, (int) Orders::get($into)['total'], 'a ₺150 discount that could take ₺100 off brings ₺100');

        [$o2] = $bill($s, false, 't1');
        [$into2] = $bill($s, false, 't3');
        Orders::discount($into2, 'pct', 10);
        $before = (int) Orders::get($o2)['total'] + (int) Orders::get($into2)['total'];
        Orders::merge($o2, $into2);
        same(19000, $before);
        same($before, (int) Orders::get($into2)['total'], '10% of the target stays 10% of its own dishes');
    },

    'R03 points used on a merged bill come back once when its discount is taken off' => function () use ($setup, $bill): void {
        $s = $setup();
        Settings::set('loyalty.point_value', 100);
        $c = Db::save('customers', ['name' => 'Müşteri', 'loyalty' => 1]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 100, 'kind' => 'adjust', 'at' => Clock::ms()]);
        [$o] = $bill($s);
        Loyalty::attach($o, $c);
        Loyalty::redeem($o, 50);
        same(50, Loyalty::balance($c));
        [$into] = $bill($s, false, 't2');
        Orders::merge($o, $into);
        same([0, 50], [Loyalty::used($o), Loyalty::used($into)], 'the points went with the discount');
        same(50, Loyalty::balance($c), 'moving them does not change the balance');
        Orders::clearDiscount($into);
        same(100, Loyalty::balance($c), 'taking the discount off gives them back');
        Orders::clearDiscount($into);
        same(100, Loyalty::balance($c), 'once');
    },

    'R04 the web copy keeps the till rule on a notification’s button and the kitchen TV too' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o] = $bill($s);
        $n = Notify::push('bill', ['where' => 'Masa 1'], null, 'waiter', $o);
        App::setConfig('role', 'web');
        App::setConfig('sync.key', str_repeat('k', 32));
        $req = new \Sofrexa\Core\Request();
        $req->params = ['id' => $n, 'act' => 'prebill'];
        try {
            (new \Sofrexa\Modules\Orders\NotifyController())->act($req);
            throw new LogicException('printed the pre-bill on the web copy');
        } catch (HttpError $e) {
            same(409, $e->status);
        }
        $r = Orders::get($o);
        same(['open', 0], [$r['status'], (int) $r['printed_bill']], 'the bill is untouched');
        try {
            Router::tillOnly();
            throw new LogicException('till work on the web copy');
        } catch (HttpError $e) {
            same(409, $e->status, 'the same rule the kitchen TV link asks for');
        }
        App::setConfig('role', 'pc');
        App::setConfig('sync.key', '');
        Router::tillOnly();
    },

    'R05 "Aldım" hands over only the plates that were on the screen; a new one keeps ringing' => function () use ($setup, $bill, $as, $waiter): void {
        $s = $setup();
        $w = $waiter('Ayşe');
        [$o, $pizza] = $bill($s);
        Orders::setWaiter($o, $w);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        $as($w);
        $shown = Notify::alerts()[0];
        same([$pizza], $shown['lines']);

        $as($s['boss']);
        $pasta = Orders::addItem($o, $s['b']);
        Orders::send($o);
        Kitchen::ready($o, 2, 'kitchen');
        $as($w);
        $now = Notify::alerts()[0];
        same($shown['id'], $now['id'], 'one alert per table');
        check($now['rev'] !== $shown['rev'], 'with a new version, so the phone draws it again');
        same(2, count($now['items']));

        $took = Notify::collect(Db::row('SELECT * FROM notifications WHERE id = ?', [$shown['id']]), $shown['lines']);
        Kitchen::served($o, $took);
        same(['served', 'ready'], [Orders::line($pizza)['status'], Orders::line($pasta)['status']], 'the pasta was not on the screen');
        $left = Notify::alerts();
        same(1, count($left), 'its alert is still open and rings');
        same([$pasta], $left[0]['lines']);
        same(['Makarna'], array_column($left[0]['items'], 'name'));
    },

    'R06 the daily stock counts half portions and cooked dishes that were cancelled' => function () use ($setup, $bill): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 1]);
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 1]);
        Orders::addItem($o, $s['a'], 0.5);
        Orders::send($o);
        same(0.5, Menu::get($s['a'])['left'], 'half a portion is left, not one');
        try {
            Orders::addItem($o, $s['a'], 1);
            throw new LogicException('sold a portion and a half of one');
        } catch (\InvalidArgumentException) {
        }
    },

    'R06 a cooked dish that was cancelled still used its portion' => function () use ($setup, $bill): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 1]);
        [$o, $pizza] = $bill($s);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Orders::voidLine($pizza, 'yanlış masa');
        same(1.0, Menu::soldToday()[$s['a']] ?? 0.0, 'the cooked pizza thrown away still used the one portion');
        check(Menu::get($s['a'])['soldout'], 'so it is sold out');
    },

    'R06 a cancelled dish that went back to stock uncooked gives its portion back' => function () use ($setup, $bill): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 1]);
        [$o, $pizza] = $bill($s);
        Orders::send($o);
        Orders::voidLine($pizza, 'müşteri vazgeçti');
        same(1.0, Menu::soldToday()[$s['a']] ?? 0.0, 'while the till has not asked the kitchen, it may be cooked');
        Orders::settleVoid($pizza, 'returned');
        same(0.0, Menu::soldToday()[$s['a']] ?? 0.0, 'back to stock: back on the menu');
    },

    'R07 moving a bill moves its whole family, split bills of split bills too' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, $a, $b] = $bill($s, true);
        $c = Orders::addItem($o, $s['a']);
        $child = Orders::split($o, [$a, $b]);
        $grand = Orders::split($child, [$b]);
        Orders::moveTable($grand, $s['t2']);
        same([$s['t2'], $s['t2'], $s['t2']], [Orders::get($o)['table_id'], Orders::get($child)['table_id'], Orders::get($grand)['table_id']], 'from the youngest, all three moved');
        Orders::moveTable($o, $s['t3']);
        same($s['t3'], Orders::get($grand)['table_id'], 'and from the first one too');
    },

    'R08 a past period lists what was on the shelf then, whatever is left today' => function () use ($setup): void {
        Clock::freeze(strtotime('2026-09-26 14:00:00') * 1000);
        $setup();
        $s = Stock::saveItem(['name' => 'Un', 'unit' => 'kg']);
        Stock::document('purchase', [['stock_item_id' => $s, 'qty' => '10', 'unit_price' => '10']]);
        Clock::freeze(strtotime('2026-09-28 14:00:00') * 1000);
        Stock::document('waste', [['stock_item_id' => $s, 'qty' => '10']]);
        [$from, $to] = Clock::dayRange('2026-09-27', 5);
        $rows = StockReport::moves($from, $to);
        same(1, count($rows), 'the flour is in the report of the 27th');
        same([10.0, 10.0, 0.0], [(float) $rows[0]['start'], (float) $rows[0]['end'], (float) $rows[0]['on_hand']]);
        same(10000, (int) $rows[0]['value'], 'at what it cost then');
    },

    'R09 calling the waiter again does not double a plate on the alert' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o] = $bill($s);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Kitchen::callAgain($o, 1, 'kitchen');
        Kitchen::callAgain($o, 1, 'kitchen');
        $b = json_arr((string) Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ?", [$o]));
        same(1, count($b['items']));
        same('Pizza', $b['what']);
    },

    'R10 the ready alert follows the bill to its new waiter, and leaves a waiter who clocks out' => function () use ($setup, $bill, $as, $waiter): void {
        $s = $setup();
        $a = $waiter('Ayşe');
        $b = $waiter('Mehmet');
        [$o] = $bill($s);
        Orders::setWaiter($o, $a);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Orders::setWaiter($o, $b);
        $as($b);
        same(1, count(Notify::alerts()), 'the new waiter hears it');
        $as($a);
        same(0, count(Notify::alerts()), 'the old one does not');
        Kitchen::callAgain($o, 1, 'kitchen');
        same(0, count(Notify::alerts()), 'not even when the kitchen calls again');

        $as($s['boss']);
        Staff::clockOut($b);
        same('waiter', Db::value("SELECT role FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$o]), 'gone home: every waiter hears it');
        $as($a);
        same(1, count(Notify::alerts()));
    },

    'R11 a guest order goes only to someone who may approve it; with nobody, to every waiter' => function () use ($setup, $as, $waiter): void {
        $s = $setup();
        $cannot = $waiter('Stajyer', ['orders.qr_approve']);
        $as(null);
        [$o] = QrOrders::submit(Floor::table($s['t1']), [['item' => $s['a'], 'qty' => 1, 'mods' => [], 'note' => '']]);
        same(null, Orders::get($o)['waiter_id'], 'not given to someone who cannot approve it');
        same('waiter', Db::value("SELECT role FROM notifications WHERE kind = 'qr' AND ref_id = ?", [$o]));
        $as($cannot);
        same(0, count(Notify::alerts()), 'and it does not ring on their phone');

        $can = $waiter('Ayşe');
        $as(null);
        [$o2] = QrOrders::submit(Floor::table($s['t2']), [['item' => $s['a'], 'qty' => 1, 'mods' => [], 'note' => '']]);
        same($can, Orders::get($o2)['waiter_id']);
    },

    'R12 a split bill is still one table in a waiter’s load' => function () use ($setup, $bill, $as, $waiter): void {
        $s = $setup();
        $w = $waiter('Ayşe');
        $as($w);
        [$o, $a] = $bill($s, true);
        Orders::split($o, [$a]);
        same(1, Assign::online()[0]['tables']);
    },

    'R13 splitting a bill with a percentage keeps what it owes to the kuruş' => function () use ($setup, $bill): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'price' => 1]);
        Db::save('items', ['id' => $s['b'], 'price' => 1]);
        [$o, $a] = $bill($s, true);
        Orders::discount($o, 'pct', 50);
        same(1, (int) Orders::get($o)['total']);
        $child = Orders::split($o, [$a]);
        same(1, (int) Orders::get($o)['total'] + (int) Orders::get($child)['total']);
    },

    'R13 splitting three ways under two discounts keeps the total too' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, $a, $b] = $bill($s, true);
        Orders::addItem($o, $s['a']);
        Orders::discount($o, 'pct', 10);
        Orders::discount($o, 'amount', 1000);
        $before = (int) Orders::get($o)['total'];
        $child = Orders::split($o, [$a]);
        $grand = Orders::split($o, [$b]);
        same($before, (int) Orders::get($o)['total'] + (int) Orders::get($child)['total'] + (int) Orders::get($grand)['total'], 'three ways, two discounts');
    },

    'decision 45 a closed day keeps its cost of sales; a later decision corrects the day it is made' => function () use ($setup, $bill): void {
        Clock::freeze(strtotime('2026-09-28 14:00:00') * 1000);
        $s = $setup();
        $flour = Stock::saveItem(['name' => 'Un', 'unit' => 'kg']);
        Stock::document('purchase', [['stock_item_id' => $flour, 'qty' => '10', 'unit_price' => '10']]);
        Stock::setRecipe('item', $s['a'], [['stock_item_id' => $flour, 'qty' => '1']]);
        [$o, $pizza] = $bill($s, true);
        Orders::send($o);
        Orders::voidLine($pizza, 'müşteri vazgeçti');
        Orders::pay($o, [['method' => 'card', 'amount' => 10000]], null, false);
        [$f, $t] = Clock::dayRange('2026-09-28', 5);
        same(1000, Reports::costOfSales($f, $t));
        Clock::freeze(strtotime('2026-09-29 14:00:00') * 1000);
        Orders::settleVoid($pizza, 'returned');
        same(1000, Reports::costOfSales($f, $t), 'the 28th stays as it was closed');
        [$f2, $t2] = Clock::dayRange('2026-09-29', 5);
        same(-1000, Reports::costOfSales($f2, $t2), 'the 29th carries the correction');
    },

    'S03 an order e-mail that failed is tried again later, then sent once' => function () use ($setup, $bill): void {
        $s = $setup();
        $keep = App::config('mail');
        $c = Db::save('customers', ['name' => 'Online Müşteri']);
        Db::save('online_accounts', ['customer_id' => $c, 'email' => 'test@example.invalid', 'password_hash' => 'x', 'verified_at' => Clock::ms()]);
        [$o] = $bill($s);
        Db::save('orders', ['id' => $o, 'channel' => 'online', 'status' => 'pending', 'customer_id' => $c, 'delivery' => ['type' => 'pickup']]);
        App::setConfig('mail', ['driver' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        same(0, @OnlineOrders::notices(), 'the mail server is down');
        $m = Db::row('SELECT status, tries FROM online_mail WHERE order_id = ?', [$o]);
        same(['retry', 1], [$m['status'], (int) $m['tries']]);
        App::setConfig('mail', ['driver' => 'log']);
        same(0, OnlineOrders::notices(), 'not before its time');
        Clock::freeze(Clock::ms() + 61_000);
        same(1, OnlineOrders::notices(), 'then it goes');
        same('sent', Db::value('SELECT status FROM online_mail WHERE order_id = ?', [$o]));
        same(0, OnlineOrders::notices(), 'and only once');
        App::setConfig('mail', $keep);
    },
];
