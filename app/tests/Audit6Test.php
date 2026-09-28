<?php
/**
 * The sixth audit (2026-09-28, D01–D03). Each finding is a class, fixed once for all its members (decision 50):
 *   D01 an alert kept a copy of where its bill was: every alert now reads the table and area from the bill itself;
 *   D02 bills end in seven places and not all of them ended the alerts: every one now goes through Orders::ended;
 *   D03 the called_at backfill took an alert that had ended as a call: only an alert still open is, and 019 repairs
 *       a database that ran the first version.
 * The random steps of Audit5Test play table moves, whole-bill cancels and take-aways too, and check where each alert
 * points after every step.
 */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Db, Migrator, View};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Delivery, Notify, Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Setup\Seed;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $kitchen = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $items = [
        Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $kitchen, 'price' => '100', 'available' => 1, 'show_qr' => 1]),
        Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $kitchen, 'price' => '90', 'available' => 1, 'show_qr' => 1]),
    ];
    $salon = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $garden = Floor::saveArea(['names' => ['tr' => 'Bahçe']]);
    $tables = [Floor::addTable($salon, '3'), Floor::addTable($garden, '12')];
    Shifts::open(['TRY' => 0]);
    return ['boss' => $boss, 'items' => $items, 'tables' => $tables];
};
$open = static fn(string $orderId): array => array_column(Db::rows('SELECT kind FROM notifications WHERE ref_type = ? AND ref_id = ? AND done_at IS NULL AND deleted = 0 ORDER BY kind', ['order', $orderId]), 'kind');
/** The database as it was just before 018: no called_at column, and 018 (and what came after it) not yet run. */
$before018 = static function (): void {
    Db::exec('ALTER TABLE order_items DROP COLUMN called_at');
    Db::exec("DELETE FROM schema_migrations WHERE name >= '018'");
};

return [
    'D01 a bill moved to another table takes its alerts along: phone, list and page show the new table' => function () use ($setup): void {
        $s = $setup();
        [$pizza] = $s['items'];
        $o = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        Orders::addItem($o, $pizza);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Notify::toWaiter('call', Orders::get($o), ['qr' => true]);
        $before = array_column(Notify::alerts(), null, 'kind')['ready'];
        same(['Masa 3', 'Salon'], [$before['where'], $before['area']]);

        Orders::moveTable($o, $s['tables'][1]);
        $after = array_column(Notify::alerts(), null, 'kind')['ready'];
        same(['Masa 12', 'Bahçe'], [$after['where'], $after['area']], 'the waiter walks to where the guests are now');
        check($after['rev'] !== $before['rev'], 'the phone draws the card again');
        foreach (Notify::forMe() as $n) {
            same('Masa 12', $n['place']['where'], 'the "' . $n['kind'] . '" alert in the list');
        }
        $html = View::render('orders/notifications', ['rows' => Notify::forMe(), 'unread' => Notify::unread()], null);
        check(str_contains($html, 'Masa 12') && !str_contains($html, 'Masa 3'), 'the notifications page names the new table only');
    },

    'D01 a take-away has no table to move to' => function () use ($setup): void {
        $s = $setup();
        $o = Orders::create('takeaway', ['label' => 'Ayşe']);
        Orders::addItem($o, $s['items'][0]);
        try {
            Orders::moveTable($o, $s['tables'][0]);
            check(false, 'refused');
        } catch (\InvalidArgumentException) {
        }
        same(null, Orders::get($o)['table_id']);
    },

    'D02 cancelling a take-away with its bag at the pass stops the till’s alert' => function () use ($setup, $open): void {
        $s = $setup();
        $o = Orders::create('takeaway', ['label' => 'Ayşe']);
        $a = Orders::addItem($o, $s['items'][0]);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        same(['ready'], array_column(Notify::alerts(), 'kind'), 'the till is called to the pass');
        Orders::void($o, 'müşteri gelmedi');
        same(['void', 'void'], [Orders::get($o)['status'], Orders::line($a)['status']]);
        same([], $open($o));
        same([], Notify::alerts(), 'nothing rings for a bag that was cancelled');
    },

    'D02 every way a bill ends ends its alerts; "food is ready" goes by the plates' => function () use ($setup, $open, $as): void {
        $s = $setup();
        [$pizza, $pasta] = $s['items'];
        // a table pays: its plates are carried out, the bill request and the waiter call are over
        $t = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        Orders::addItem($t, $pizza);
        Orders::send($t);
        Kitchen::ready($t, 1, 'kitchen');
        Notify::toWaiter('call', Orders::get($t), ['qr' => true]);
        Notify::push('bill', ['where' => 'Masa 3', 'by' => 'Patron'], null, 'cashier', $t);
        same(['bill', 'call', 'ready'], $open($t));
        Orders::pay($t, [['method' => 'cash', 'amount' => (int) Orders::get($t)['total']]], null, false);
        same([], $open($t));

        // a merged-away bill: its alerts end, its called plate rings on the bill it went to
        $from = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        $a = Orders::addItem($from, $pizza);
        Orders::send($from);
        Kitchen::ready($from, 1, 'kitchen');
        Notify::toWaiter('call', Orders::get($from), ['qr' => true]);
        $into = Orders::create('table', ['table_id' => $s['tables'][1], 'guests' => 2]);
        Orders::addItem($into, $pasta);
        Orders::merge($from, $into);
        same([], $open($from));
        same([$a], json_arr((string) Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$into]))['lines']);

        // a paid take-away whose bag still waits at the pass keeps calling the till until it is handed over
        $bag = Orders::create('takeaway', ['label' => 'Ali']);
        $b = Orders::addItem($bag, $pizza);
        Orders::send($bag);
        Kitchen::ready($bag, 1, 'kitchen');
        Orders::pay($bag, [['method' => 'card', 'amount' => (int) Orders::get($bag)['total']]], null, false);
        same(['paid', 'ready'], [Orders::get($bag)['status'], Orders::line($b)['status']]);
        same(['ready'], $open($bag), 'paid, not yet handed over');
        Kitchen::served($bag);
        same([], $open($bag));

        // a guest order turned down
        $table = Floor::table($s['tables'][0]);
        $as(null);
        [$guest] = QrOrders::submit($table, [['item' => $pizza, 'qty' => 1, 'mods' => [], 'note' => '']]);
        $as($s['boss']);
        same(['qr'], $open($guest));
        QrOrders::reject($guest);
        same([], $open($guest));
    },

    'D02 a delivery bag leaving with the courier is served: nothing calls the till for it afterwards' => function () use ($setup, $open): void {
        $s = $setup();
        $courier = Seed::user('Emre Kurye', 'courier', '6060');
        $id = Delivery::create(['type' => 'delivery', 'phone' => '0533 412 77 90', 'name' => 'Ahmet Yılmaz', 'address' => 'Levent Mah. 12. Sk. No 4, Girne',
            'courier_id' => $courier, 'pay' => 'cash', 'items' => [['item_id' => $s['items'][0], 'qty' => 1]]]);
        Kitchen::ready($id, 1, 'kitchen');
        same(['ready'], $open($id));
        Delivery::move($id, 'way');
        same(['served'], array_column(Db::rows('SELECT status FROM order_items WHERE order_id = ? AND deleted = 0', [$id]), 'status'));
        same([], $open($id));
    },

    'a waiter change moves the waiter’s alerts, never what the till was asked' => function () use ($setup): void {
        $s = $setup();
        $w = Seed::user('Garson', 'waiter', '2345');
        Staff::clockIn($w);
        $o = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        Orders::addItem($o, $s['items'][0]);
        Orders::send($o);
        Kitchen::ready($o, 1, 'kitchen');
        Notify::push('bill', ['where' => 'Masa 3', 'by' => 'Patron'], null, 'cashier', $o);
        Orders::setWaiter($o, $w);
        $to = Db::pairs("SELECT kind, COALESCE(user_id, role) FROM notifications WHERE ref_id = ? AND done_at IS NULL ORDER BY kind", [$o]);
        same(['bill' => 'cashier', 'ready' => $w], [...$to]);
    },

    'D03 upgrading: a plate on an ended alert is not called; one on an open alert is; a plated one is not' => function () use ($setup, $before018): void {
        $s = $setup();
        [$pizza, $pasta] = $s['items'];
        // called, taken back, plated again: its alert has ended — it waits for "Hazır"
        $x = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        $a = Orders::addItem($x, $pizza);
        $b = Orders::addItem($x, $pasta);
        Orders::send($x);
        Kitchen::ready($x, 1, 'kitchen');
        Kitchen::recall($x, 1, 'kitchen');
        Kitchen::toggleLine($a);
        // called and waiting: its alert is open
        $y = Orders::create('table', ['table_id' => $s['tables'][1], 'guests' => 2]);
        $c = Orders::addItem($y, $pizza);
        Orders::send($y);
        Kitchen::ready($y, 1, 'kitchen');
        // only plated on a ticket not finished
        $z = Orders::create('takeaway', ['label' => 'Ali']);
        $d = Orders::addItem($z, $pizza);
        Orders::addItem($z, $pasta);
        Orders::send($z);
        Kitchen::toggleLine($d);
        $alerts = Db::rows('SELECT id, done_at, body FROM notifications ORDER BY id');

        $before018();
        $ran = Migrator::run();
        same([null, 'sent'], [Orders::line($a)['called_at'], Orders::line($b)['status']], 'the plate taken back and plated again');
        check(Orders::line($c)['called_at'] !== null, 'the plate on the open alert');
        same(null, Orders::line($d)['called_at'], 'the plated plate');
        same($alerts, Db::rows('SELECT id, done_at, body FROM notifications ORDER BY id'), 'no alert changed');
        same(2, $ran, '018 and 019');

        $child = Orders::split($x, [$a]);
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'ready' AND ref_id IN (?, ?) AND done_at IS NULL", [$x, $child]), 'splitting it off calls nobody');
        Kitchen::retell($y);
        same([$c], json_arr((string) Db::value("SELECT body FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$y]))['lines'], 'still one alert, the same plate');
    },

    'D03 019 repairs a database that ran the first 018' => function () use ($setup): void {
        $s = $setup();
        $x = Orders::create('table', ['table_id' => $s['tables'][0], 'guests' => 2]);
        $a = Orders::addItem($x, $s['items'][0]);
        Orders::addItem($x, $s['items'][1]);
        Orders::send($x);
        Kitchen::ready($x, 1, 'kitchen');
        Kitchen::recall($x, 1, 'kitchen');
        Kitchen::toggleLine($a);
        $y = Orders::create('table', ['table_id' => $s['tables'][1], 'guests' => 2]);
        $c = Orders::addItem($y, $s['items'][0]);
        Orders::send($y);
        Kitchen::ready($y, 1, 'kitchen');
        // what the first 018 left: the plate named on the ended alert marked as called
        Db::exec('UPDATE order_items SET called_at = 1 WHERE id = ?', [$a]);
        $called = Orders::line($c)['called_at'];
        Db::exec("DELETE FROM schema_migrations WHERE name = '019_called_repair'");
        same(1, Migrator::run());
        same([null, $called], [Orders::line($a)['called_at'], Orders::line($c)['called_at']]);
    },
];
