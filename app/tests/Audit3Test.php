<?php
/**
 * The findings of the third audit (2026-09-28, A01–A07), each with the result it must have: a refund sends what is
 * unsent and gives back account money to the account, points match what they really took off, and a plate waiting at
 * the pass always has one alert — on the bill it is on, for a waiter who is at work.
 */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db, Settings};
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Accounts, Notify, Orders, Shifts};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Setup\Seed;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $a = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $b = Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $c = Menu::saveItem(['names' => ['tr' => 'Çorba'], 'category_id' => $cat, 'price' => '10', 'available' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $t1 = Floor::addTable($area);
    $t2 = Floor::addTable($area);
    Shifts::open(['TRY' => 0]);
    return compact('boss', 'cat', 'a', 'b', 'c', 't1', 't2');
};
/** A ₺100 pizza and a ₺100 pasta on a table's bill: [order, pizza line, pasta line]. */
$bill = static function (array $s, string $table = 't1'): array {
    $o = Orders::create('table', ['table_id' => $s[$table], 'guests' => 2]);
    return [$o, Orders::addItem($o, $s['a']), Orders::addItem($o, $s['b'])];
};
$customer = static fn(): string => Db::save('customers', ['name' => 'Cari Müşteri', 'credit_enabled' => 1]);
$waiter = static function (string $name): string {
    $u = Seed::user($name, 'waiter', (string) random_int(1000, 9999));
    Staff::clockIn($u);
    return $u;
};
$openReady = static fn(string $orderId): int => (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$orderId]);

return [
    'A01 a refund sends a dish added since, before the bill closes' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, , $pasta] = $bill($s);
        Orders::pay($o, [['method' => 'card', 'amount' => 15000]], null, false);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        $soup = Orders::addItem($o, $s['c']);
        same(4000, Orders::refund($o, 'card', false), '₺150 paid, the bill is ₺110 now');
        same('paid', Orders::get($o)['status']);
        same('sent', Orders::line($soup)['status'], 'the soup went to the kitchen');
        check((bool) Orders::line($soup)['sent_at'], 'with its time');
    },

    'A01 a refund is refused when the dish added since cannot be sent (daily stock)' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, , $pasta] = $bill($s);
        Orders::pay($o, [['method' => 'card', 'amount' => 15000]], null, false);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        Orders::addItem($o, $s['c']);
        Db::save('items', ['id' => $s['c'], 'daily_stock' => 0]);
        try {
            Orders::refund($o, 'card', false);
            throw new LogicException('closed with an unsendable dish');
        } catch (\InvalidArgumentException) {
        }
        same('open', Orders::get($o)['status'], 'nothing happened');
        same(0, (int) Db::value('SELECT COUNT(*) FROM payments WHERE order_id = ? AND amount < 0', [$o]));
    },

    'A02 what was paid on account comes off the account, not out of the drawer' => function () use ($setup, $bill, $customer): void {
        $s = $setup();
        $c = $customer();
        [$o, , $pasta] = $bill($s);
        Orders::pay($o, [['method' => 'account', 'amount' => 15000]], $c, false);
        same(15000, Accounts::balance($c));
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        same(['over' => 5000, 'account' => [$c => 5000], 'money' => 0, 'card' => 0], Orders::refundPlan(Orders::get($o)));
        Orders::refund($o, 'cash', false);
        same(10000, Accounts::balance($c), 'the debt is what was eaten');
        same(0, (int) Db::value("SELECT COUNT(*) FROM payments WHERE order_id = ? AND method = 'cash'", [$o]), 'no cash left the drawer');
        same([10000, 10000, 'paid'], [(int) Orders::get($o)['total'], (int) Orders::get($o)['paid'], Orders::get($o)['status']]);
        same('refund', Db::value("SELECT kind FROM account_ledger WHERE customer_id = ? AND amount < 0", [$c]), 'its own line on the statement');
    },

    'A02 mixed: the account part comes off the account, the rest goes back on the card' => function () use ($setup, $bill, $customer): void {
        $s = $setup();
        $c = $customer();
        [$o, , $pasta] = $bill($s);
        Orders::pay($o, [['method' => 'account', 'amount' => 5000], ['method' => 'card', 'amount' => 12000]], $c, false, 17000);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        $plan = Orders::refundPlan(Orders::get($o));
        same([7000, 5000, 2000], [$plan['over'], $plan['account'][$c], $plan['money']]);
        Orders::refund($o, 'card', false);
        same(0, Accounts::balance($c), 'the ₺50 on account is gone');
        same(-2000, (int) Db::value("SELECT SUM(amount) FROM payments WHERE order_id = ? AND method = 'card' AND amount < 0", [$o]));
        same('paid', Orders::get($o)['status']);
    },

    'A02 no more goes back on the card than was taken on it' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o, , $pasta] = $bill($s);
        Orders::pay($o, [['method' => 'cash', 'amount' => 15000]], null, false);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        try {
            Orders::refund($o, 'card', false);
            throw new LogicException('refunded cash money onto a card');
        } catch (\InvalidArgumentException) {
        }
        same(5000, Orders::refund($o, 'cash', false), 'in cash it goes');
    },

    'A03 a plate added between reading the alert and "Aldım" keeps its alert' => function () use ($setup): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        $stale = Db::row("SELECT * FROM notifications WHERE kind = 'ready' AND done_at IS NULL");
        $b = Orders::addItem($o, $s['b']);
        Orders::send($o);
        Kitchen::ready($o, 2, 'kitchen');
        Kitchen::served($o, Notify::collect($stale, [$a]));
        same(['served', 'ready'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        same(1, count(Notify::alerts()), 'the pasta still rings');
        same([$b], Notify::alerts()[0]['lines']);
    },

    'A04 calling again after the waiter clocked out does not call them back' => function () use ($setup, $as, $waiter): void {
        $s = $setup();
        $a = $waiter('Ayşe');
        $b = $waiter('Mehmet');
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        Orders::addItem($o, $s['a']);
        Orders::setWaiter($o, $a);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Staff::clockOut($a);
        Kitchen::callAgain($o, 1, 'kitchen');
        $as($b);
        same(1, count(Notify::alerts()), 'Mehmet, at work, hears it');
        $as($a);
        same(0, count(Notify::alerts()), 'Ayşe, gone home, does not');
        same($a, Orders::get($o)['waiter_id'], 'the bill is still hers (her sales)');
        $as($s['boss']);
        Orders::setWaiter($o, $a);
        same('waiter', Db::value("SELECT role FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$o]), 'given back to her while off: still everyone');
    },

    'A05 a split moves the plate’s alert to the bill the plate went to' => function () use ($setup, $bill, $openReady): void {
        $s = $setup();
        [$o, $a, $b] = $bill($s);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        $child = Orders::split($o, [$b]);
        same([$a], json_arr((string) Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$o]))['lines']);
        same([$b], json_arr((string) Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$child]))['lines']);
        $n = Db::row("SELECT * FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$o]);
        Kitchen::served($o, Notify::collect($n, [$a, $b]));
        same(['served', 'ready'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        same([0, 1], [$openReady($o), $openReady($child)], 'the pasta, on the other bill, still has its alert');
    },

    'A05 a merge moves the plate’s alert to the merged bill' => function () use ($setup, $bill, $openReady): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        $into = Orders::create('table', ['table_id' => $s['t2'], 'guests' => 2]);
        Orders::addItem($into, $s['b']);
        Orders::merge($o, $into);
        same([0, 1], [$openReady($o), $openReady($into)]);
        $n = Db::row("SELECT * FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$into]);
        Kitchen::served($n['ref_id'], Notify::collect($n, [$a]));
        same('served', Orders::line($a)['status'], '"Aldım" on it hands the plate over');
    },

    'A06 taking one round back leaves the other round’s alert ringing' => function () use ($setup, $openReady): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        $read = Db::value("SELECT id FROM notifications WHERE kind = 'ready' AND ref_id = ?", [$o]);
        Notify::later((string) $read);
        $b = Orders::addItem($o, $s['b']);
        Orders::send($o);
        Kitchen::ready($o, 2, 'kitchen');
        Notify::later((string) $read);
        Kitchen::recall($o, 2, 'kitchen');
        same(['ready', 'sent'], [Orders::line($a)['status'], Orders::line($b)['status']]);
        same(1, $openReady($o));
        $n = Db::row('SELECT body, read_at FROM notifications WHERE id = ?', [$read]);
        same([$a], json_arr((string) $n['body'])['lines'], 'only the pizza is on it');
        check($n['read_at'] !== null, 'and taking a plate back does not ring again');
    },

    'A06 a cancelled ready plate leaves its alert' => function () use ($setup, $openReady): void {
        $s = $setup();
        $o = Orders::create('table', ['table_id' => $s['t1'], 'guests' => 2]);
        $a = Orders::addItem($o, $s['a']);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Orders::voidLine($a, 'yanlış masa');
        same(0, $openReady($o), 'nothing to carry out');
    },

    'A07 points used on a bill that then shrank: the unused ones come back when it closes' => function () use ($setup, $bill): void {
        $s = $setup();
        Settings::set('loyalty.point_value', 100);
        $c = Db::save('customers', ['name' => 'Puanlı', 'loyalty' => 1]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 200, 'kind' => 'adjust', 'at' => Clock::ms()]);
        [$o, , $pasta] = $bill($s);
        Loyalty::attach($o, $c);
        Loyalty::redeem($o, 150);
        Orders::send($o);
        Orders::voidLine($pasta, 'müşteri vazgeçti');
        Orders::pay($o, [], null, false);
        same('paid', Orders::get($o)['status']);
        same([100, 100], [Loyalty::used($o), Loyalty::balance($c)], '₺100 of points really used, 50 back');
        same(10000, (int) Orders::get($o)['discount']);
    },

    'A07 a bill that stays as big as the points keeps them all' => function () use ($setup, $bill): void {
        $s = $setup();
        Settings::set('loyalty.point_value', 100);
        $c = Db::save('customers', ['name' => 'Puanlı', 'loyalty' => 1]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 200, 'kind' => 'adjust', 'at' => Clock::ms()]);
        [$o] = $bill($s);
        Loyalty::attach($o, $c);
        Loyalty::redeem($o, 150);
        Orders::pay($o, [['method' => 'cash', 'amount' => 5000]], null, false);
        same('paid', Orders::get($o)['status']);
        same([150, 50], [Loyalty::used($o), Loyalty::balance($c)]);
    },
];
