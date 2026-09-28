<?php
/**
 * The findings of the fourth audit (2026-09-28, B01–B05): points settled before a merge or split freezes them, the
 * ready alert keeps the count and the plates the kitchen really has, the refund screen shows each account's own new
 * balance, and a customer without a tier does not break the export — nor a deprecation the till's page.
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Settings, View};
use Sofrexa\Modules\Customers\{Customers, Loyalty};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Accounts, Orders, Rates, Shifts, Till};
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$boss]));
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $a = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $b = Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $t1 = Floor::addTable($area);
    $t2 = Floor::addTable($area);
    Shifts::open(['TRY' => 0]);
    return compact('boss', 'cat', 'a', 'b', 't1', 't2');
};
/** A customer with 200 points worth ₺1 each, and a ₺200 bill (pizza + pasta) that used 150 of them. */
$pointsBill = static function (array $s): array {
    Settings::set('loyalty.point_value', 100);
    $c = Db::save('customers', ['name' => 'Puanlı', 'loyalty' => 1]);
    Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 200, 'kind' => 'adjust', 'at' => Clock::ms()]);
    $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
    $pizza = Orders::addItem($o, $s['a']);
    $pasta = Orders::addItem($o, $s['b']);
    Loyalty::attach($o, $c);
    Loyalty::redeem($o, 150);
    Orders::send($o);
    return [$c, $o, $pizza, $pasta];
};
$alert = static fn(string $orderId): ?array => ($b = Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$orderId])) ? json_arr((string) $b) : null;

return [
    'B01 a bill that shrank and is then merged gives its unused points back' => function () use ($setup, $pointsBill): void {
        $s = $setup();
        [$c, $o, , $pasta] = $pointsBill($s);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        $into = Orders::create('table', ['table_id' => $s['t2'], 'guests' => 2]);
        Orders::addItem($into, $s['b']);
        Orders::merge($o, $into);
        same(50, Loyalty::balance($c) - 50, 'settled at the merge: 50 back already');
        Orders::pay($into, [['method' => 'cash', 'amount' => 10000]], null, false);
        same(['paid', 10000], [Orders::get($into)['status'], (int) Orders::get($into)['discount']]);
        same([100, 100], [Loyalty::used($into), Loyalty::balance($c)], '₺100 of points used, 100 left');
    },

    'B01 a split that would leave the points uncovered is refused and changes nothing' => function () use ($setup, $pointsBill): void {
        $s = $setup();
        [$c, $o, , $pasta] = $pointsBill($s);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        $extra = Orders::addItem($o, $s['b']);
        Orders::send($o);
        try {
            Orders::split($o, [$extra]);
            throw new LogicException('split off what the points were used for');
        } catch (\InvalidArgumentException) {
        }
        same([50, 150], [Loyalty::balance($c), Loyalty::used($o)], 'the points were not touched');
        Orders::pay($o, [['method' => 'cash', 'amount' => 5000]], null, false);
        same([150, 50], [Loyalty::used($o), Loyalty::balance($c)], 'a ₺200 bill again: all 150 used');
    },

    'B02 a partly cancelled ready plate shows its new count on the alert' => function () use ($setup, $alert): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 3]);
        $l = Orders::addItem($o, $s['a'], 3);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        same('3', $alert($o)['items'][0]['qty']);
        $before = Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ?", [$o]);
        Orders::voidLine($l, 'bir tanesi yanlış', 1);
        same(2.0, (float) Orders::line($l)['qty']);
        same(['2', 'Pizza ×2'], [$alert($o)['items'][0]['qty'], $alert($o)['what']]);
        check(Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ?", [$o]) !== $before, 'a new version, so the phone redraws it');
    },

    'B03 tapping a ready plate back on the kitchen screen takes it off the alert' => function () use ($setup, $alert): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $pizza = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        same('sent', Kitchen::toggleLine($pizza));
        same(null, $alert($o), 'the only plate went back: no alert');

        $o = Orders::create('table', ['table_id' => $s['t2'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        $b = Orders::addItem($o, $s['b']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Kitchen::toggleLine($b);
        same([$a], $alert($o)['lines'], 'one of two went back: the other stays');
    },

    'B03 a plate tapped as plated on an unfinished ticket is not announced by a split' => function () use ($setup, $alert): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        $b = Orders::addItem($o, $s['b']);
        $c = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::toggleLine($a);
        same(['ready', 'sent'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        Orders::split($o, [$c]);
        same(null, $alert($o), 'the ticket is not "Hazır" yet: the waiter is not called');
    },

    'B04 the refund screen shows each account’s own share and new balance' => function () use ($setup): void {
        $s = $setup();
        $ali = Db::save('customers', ['name' => 'Ali Cari', 'credit_enabled' => 1]);
        $veli = Db::save('customers', ['name' => 'Veli Cari', 'credit_enabled' => 1]);
        Db::save('items', ['id' => $s['a'], 'price' => 5000]);
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 4]);
        Orders::addItem($o, $s['a']);
        $pastas = Orders::addItem($o, $s['b'], 3);
        Orders::send($o);
        Orders::pay($o, [['method' => 'account', 'amount' => 10000]], $ali, false, 10000);
        Orders::pay($o, [['method' => 'account', 'amount' => 5000]], $veli, false, 5000);
        Orders::voidLine($pastas, 'masadan kalktılar');
        $r = Orders::get($o);
        same([5000, 15000], [(int) $r['total'], (int) $r['paid']]);
        $plan = Orders::refundPlan($r);
        same([$veli => 5000, $ali => 5000], $plan['account'], 'the newest account payment first');
        $html = View::render('cashier/pay', ['o' => $r, 'due' => 0, 'over' => $plan['over'], 'plan' => $plan, 'share' => 0, 'persons' => 0, 'done' => 0,
            'rates' => Rates::detail(), 'currencies' => Till::currencies(), 'shift' => Shifts::current(), 'discountText' => '', 'vat' => Orders::vat($o), 'customer' => null], null);
        check(str_contains($html, 'Ali Cari: ₺50 düşülür · yeni bakiye ₺50'), 'Ali: ₺100 on account, ₺50 off');
        check(str_contains($html, 'Veli Cari: ₺50 düşülür · yeni bakiye ₺0'), 'Veli: ₺50 on account, all of it off');
        Orders::refund($o, 'cash', false);
        same([5000, 0], [Accounts::balance($ali), Accounts::balance($veli)], 'and that is what was booked');
    },

    'B05 the customer export takes customers without a tier' => function () use ($setup): void {
        $setup();
        $c = Customers::quickSave('Seviyesiz', '5550004444');
        Loyalty::setTier($c, null, false, 'test');
        same(null, Db::value('SELECT tier_id FROM customers WHERE id = ?', [$c]));
        $csv = Customers::csv(Customers::list());
        check(str_contains($csv, 'Seviyesiz'), 'the customer is in the file');
    },

    'B05 in the restaurant a deprecation is logged, not turned into an error page' => function (): void {
        $keep = App::config('debug');
        App::setConfig('debug', false);
        $log = App::storage('logs') . '/php-deprecated.log';
        $size = is_file($log) ? filesize($log) : 0;
        trigger_error('test deprecation', E_USER_DEPRECATED);
        clearstatcache();
        check(is_file($log) && filesize($log) > $size, 'written to the log');
        App::setConfig('debug', true);
        try {
            trigger_error('test deprecation', E_USER_DEPRECATED);
            throw new LogicException('not raised while developing');
        } catch (\ErrorException) {
        }
        App::setConfig('debug', $keep);
    },
];
