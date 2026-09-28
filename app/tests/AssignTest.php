<?php
/** Sharing guest orders between the waiters on shift, and the alerts their phones ring about (W7). */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Assign, Notify, Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Setup\Seed;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $ayse = Seed::user('Ayşe Yıldız', 'waiter', '2222');
    $mehmet = Seed::user('Mehmet Kaya', 'waiter', '3333');
    $kasa = Seed::user('Kasa Kemal', 'cashier', '4444');
    $as($boss);
    $kitchen = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $grill = Menu::saveItem(['names' => ['tr' => 'Adana Kebap'], 'category_id' => $kitchen, 'price' => '870', 'available' => 1, 'show_qr' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Bahçe']]);
    $t1 = Floor::table(Floor::addTable($area, '1'));
    $t2 = Floor::table(Floor::addTable($area, '2'));
    $t3 = Floor::table(Floor::addTable($area, '3'));
    Shifts::open(['TRY' => 0]);
    $as(null); // a guest has no account
    return compact('boss', 'ayse', 'mehmet', 'kasa', 'grill', 't1', 't2', 't3');
};
$line = static fn(string $item, int $qty = 1): array => ['item' => $item, 'qty' => $qty, 'mods' => [], 'note' => ''];
/** The waiter a guest order ended up with. */
$owner = static fn(string $orderId): ?string => Db::value('SELECT waiter_id FROM orders WHERE id = ?', [$orderId]);

return [
    'nobody on shift: the alert still goes to every waiter' => function () use ($setup, $line, $owner): void {
        $s = $setup();
        same(null, Assign::pick(), 'no clock-in, nobody to give it to');
        [$o] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        same(null, $owner($o), 'the order stays unowned');
        same('waiter', Db::value("SELECT role FROM notifications WHERE kind = 'qr' AND ref_id = ?", [$o]), 'the whole role is called');
    },

    'two waiters on shift: the orders go one each, not both to the quickest' => function () use ($setup, $as, $line, $owner): void {
        $s = $setup();
        Staff::clockIn($s['ayse']);
        Staff::clockIn($s['mehmet']);
        same(2, count(Assign::online()));

        [$o1] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        [$o2] = QrOrders::submit($s['t2'], [$line($s['grill'])]);
        $w1 = $owner($o1);
        $w2 = $owner($o2);
        check($w1 !== null && $w2 !== null, 'both got a waiter');
        check($w1 !== $w2, 'and not the same one');
        same(null, Db::value("SELECT role FROM notifications WHERE kind = 'qr' AND ref_id = ?", [$o1]), 'the alert is addressed to one person');
        same($w1, Db::value("SELECT user_id FROM notifications WHERE kind = 'qr' AND ref_id = ?", [$o1]));
    },

    'the waiter with plates under the lamp is skipped, even when it is their turn' => function () use ($setup, $as, $line, $owner): void {
        $s = $setup();
        Staff::clockIn($s['ayse']);
        Staff::clockIn($s['mehmet']);

        // Ayşe opens a table and the kitchen calls it ready: three plates waiting to be carried out
        $as($s['ayse']);
        $own = Orders::create('table', ['table_id' => $s['t3']['id'], 'guests' => 2]);
        Orders::addItem($own, $s['grill'], 3);
        Orders::send($own);
        Kitchen::ready($own, 1, 'kitchen');
        same(3.0, (float) Db::value("SELECT SUM(qty) FROM order_items WHERE order_id = ? AND status = 'ready'", [$own]), 'three plates waiting');

        $as(null);
        $load = [];
        foreach (Assign::online() as $w) {
            $load[$w['id']] = $w['load'];
        }
        check($load[$s['ayse']] > $load[$s['mehmet']], 'Ayşe counts as the busier one');
        [$o] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        same($s['mehmet'], $owner($o), 'so the guest order goes to Mehmet');
    },

    'a cashier only stands in when no waiter is on shift' => function () use ($setup, $line, $owner): void {
        $s = $setup();
        Staff::clockIn($s['kasa']);
        same($s['kasa'], Assign::pick(), 'the cashier takes orders too');
        Staff::clockIn($s['ayse']);
        same($s['ayse'], Assign::pick(), 'but a waiter comes first');
        [$o] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        same($s['ayse'], $owner($o));
    },

    'a table that already has a waiter keeps them' => function () use ($setup, $as, $line): void {
        $s = $setup();
        Staff::clockIn($s['ayse']);
        Staff::clockIn($s['mehmet']);
        $as($s['mehmet']);
        $bill = Orders::create('table', ['table_id' => $s['t1']['id'], 'guests' => 2]);
        Orders::addItem($bill, $s['grill'], 1);
        Orders::send($bill);

        $as(null);
        [$o] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        same($s['mehmet'], Db::value("SELECT user_id FROM notifications WHERE kind = 'qr' AND ref_id = ?", [$o]), 'the guest order belongs to the table waiter');
    },

    'the ready alert rings on the phone of the waiter who owns the table, and stops on "Sonra"' => function () use ($setup, $as, $line): void {
        $s = $setup();
        Staff::clockIn($s['ayse']);
        $as($s['ayse']);
        $bill = Orders::create('table', ['table_id' => $s['t1']['id'], 'guests' => 2]);
        Orders::addItem($bill, $s['grill'], 2);
        Orders::send($bill);
        same(0, count(Notify::alerts()), 'nothing to ring about while it is still cooking');

        Kitchen::ready($bill, 1, 'kitchen');
        $a = Notify::alerts();
        same(1, count($a));
        same('ready', $a[0]['kind']);
        check(str_contains($a[0]['what'], 'Adana'), 'the card says what is waiting');

        $as($s['mehmet']);
        same(0, count(Notify::alerts()), 'another waiter is not woken for somebody else\'s table');

        $as($s['ayse']);
        Notify::later($a[0]['id']);
        same(0, count(Notify::alerts()), '"Sonra" stops the ringing');
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE id = ? AND done_at IS NULL", [$a[0]['id']]), 'but the alert is still open');
    },

    'taking an order gives it an assigned_at, so the next one goes to somebody else' => function () use ($setup, $as, $line, $owner): void {
        $s = $setup();
        Staff::clockIn($s['ayse']);
        Staff::clockIn($s['mehmet']);
        $as($s['ayse']);
        $own = Orders::create('table', ['table_id' => $s['t3']['id'], 'guests' => 2]);
        check((bool) Db::value('SELECT assigned_at FROM orders WHERE id = ?', [$own]), 'a waiter owns what they open');

        // equal load (one open table each) → the one who has waited longest takes the guest order
        $as($s['mehmet']);
        $his = Orders::create('table', ['table_id' => $s['t2']['id'], 'guests' => 2]);
        Db::save('orders', ['id' => $his, 'assigned_at' => Clock::ms() - 600_000]);
        $as(null);
        [$o] = QrOrders::submit($s['t1'], [$line($s['grill'])]);
        same($s['mehmet'], $owner($o), 'Mehmet went longest without one');
    },
];
