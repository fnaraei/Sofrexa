<?php
/** Kitchen and bar screens: tickets per round and station, late states, ready → waiter alert, recall, served. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Notify, Orders};
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $waiter = Seed::user('Ayşe Garson', 'waiter', '1212');
    $chef = Seed::user('Şef Ali', 'chef', '3434');
    $row = static fn(string $id): array => Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    Auth::actAs($row($waiter));
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $bar = Db::save('categories', ['names' => ['tr' => 'İçecekler'], 'station' => 'bar', 'vat_rate' => 10]);
    $adana = Menu::saveItem(['names' => ['tr' => 'Adana Kebap'], 'category_id' => $cat, 'price' => '870', 'available' => 1]);
    $ayran = Menu::saveItem(['names' => ['tr' => 'Ayran'], 'category_id' => $bar, 'price' => '50', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    return compact('adana', 'ayran', 'table', 'waiter', 'chef', 'row');
};

return [
    'tickets: one per round and station, late after the set minutes' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        $t0 = strtotime('2026-09-28 19:00') * 1000;
        Clock::freeze($t0);
        $o = Orders::forTable($table, 2);
        Orders::addItem($o, $adana, 2);
        Orders::addItem($o, $ayran, 2);
        Orders::send($o);
        Clock::freeze($t0 + 5 * 60_000);
        Orders::addItem($o, $adana, 1);
        Orders::send($o);
        $k = Kitchen::tickets('kitchen');
        same(2, count($k), 'two kitchen rounds');
        same(1, count(Kitchen::tickets('bar')));
        same('MASA 1', $k[0]['title']);
        same('Bahçe', $k[0]['area']);
        Clock::freeze($t0 + 16 * 60_000);
        same('late', Kitchen::tickets('kitchen')[0]['state']);
        same('new', Kitchen::tickets('kitchen')[1]['state'], 'not started yet');
        Kitchen::start($o, 2, 'kitchen');
        same('cooking', Kitchen::tickets('kitchen')[1]['state']);
        same(660, Kitchen::tickets('kitchen')[1]['seconds'], 'timer runs from sending');
        Clock::freeze(null);
    },

    'ready tells the waiter, several plates join one alert, recall undoes, "Aldım" serves' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table, 'waiter' => $waiter, 'chef' => $chef, 'row' => $row] = $setup();
        $o = Orders::forTable($table, 2);
        Orders::addItem($o, $adana, 2);
        Orders::addItem($o, $ayran, 1);
        Orders::send($o);
        Auth::actAs($row($chef));
        same(1, Kitchen::ready($o, 1, 'kitchen'));
        same(1, Kitchen::ready($o, 1, 'bar'));
        Auth::actAs($row($waiter));
        $n = Notify::forMe();
        same(1, count($n), 'one alert for the table');
        $body = json_arr($n[0]['body']);
        same('Adana Kebap ×2, Ayran', $body['what']);
        same(2, count($body['lines']));
        same('ready', Kitchen::tickets('kitchen')[0]['state'], 'stays on screen until the waiter takes it');
        Auth::actAs($row($chef));
        same(1, Kitchen::recall($o, 1, 'kitchen'));
        same('sent', Db::value("SELECT status FROM order_items WHERE order_id = ? AND station = 'kitchen'", [$o]));
        Kitchen::ready($o, 1, 'kitchen');
        Kitchen::served($o);
        same(0, (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status <> 'served'", [$o]));
        same([], Kitchen::tickets('kitchen'), 'gone from the screen');
    },

    'tapping items: plated one by one, the last one tells the waiter' => function () use ($setup): void {
        ['adana' => $adana, 'table' => $table, 'waiter' => $waiter, 'chef' => $chef, 'row' => $row] = $setup();
        $cat = Db::value("SELECT category_id FROM items WHERE id = ?", [$adana]);
        $kofte = Menu::saveItem(['names' => ['tr' => 'Izgara Köfte'], 'category_id' => $cat, 'price' => '420', 'available' => 1]);
        $o = Orders::forTable($table);
        $a = Orders::addItem($o, $adana, 1);
        $k = Orders::addItem($o, $kofte, 2);
        Orders::send($o);
        Auth::actAs($row($chef));
        same('ready', Kitchen::toggleLine($a));
        same(0, (int) Db::value('SELECT COUNT(*) FROM notifications'), 'no alert for one plate');
        same('cooking', Kitchen::tickets('kitchen')[0]['state'], 'a tap starts the ticket');
        same('ready', Kitchen::toggleLine($k));
        same('ready', Kitchen::tickets('kitchen')[0]['state']);
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'ready'"));
        same('sent', Kitchen::toggleLine($a), 'undo one plate');
    },

    'overview: open tickets per station, one row per order and the display token' => function () use ($setup): void {
        ['adana' => $adana, 'ayran' => $ayran, 'table' => $table] = $setup();
        $o = Orders::forTable($table);
        Orders::addItem($o, $adana, 3);
        Orders::addItem($o, $ayran, 1);
        Orders::send($o);
        Kitchen::ready($o, 1, 'bar');
        $ov = Kitchen::overview();
        same(1, $ov['stations']['kitchen']);
        same(0, $ov['stations']['bar'], 'the ready bar ticket is not open');
        same(1, count($ov['ready']));
        same([0, 1], $ov['orders'][0]['kitchen']);
        same([1, 1], $ov['orders'][0]['bar']);
        $t = Kitchen::displayToken();
        check(Kitchen::checkToken($t) && !Kitchen::checkToken('x' . $t), 'token check');
        check(Kitchen::displayToken(true) !== $t, 'renewed');
    },
];
