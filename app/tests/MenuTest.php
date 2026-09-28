<?php
/** Menu and floor: prices with history, bulk rounding, daily stock → sold out, table codes and QR links. */
declare(strict_types=1);

use Sofrexa\Core\{Clock, Db, Settings};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Support\Qr;

$item = static function (int $price = 87000, ?int $stock = null): string {
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen', 'vat_rate' => 10]);
    return Menu::saveItem(['names' => ['tr' => 'Adana Kebap', 'en' => 'Adana Kebab'], 'category_id' => $cat, 'price' => number_format($price / 100, 0, ',', '.'),
        'available' => 1, 'show_web' => 1, 'show_qr' => 1, 'show_online' => 1]) . ($stock !== null ? '' : '');
};

return [
    'item: price change keeps the old price in history' => function () use ($item): void {
        $id = $item();
        same(87000, (int) Db::value('SELECT price FROM items WHERE id = ?', [$id]));
        same('adana-kebab', Db::value('SELECT slug FROM items WHERE id = ?', [$id]));
        Menu::quick([$id => ['price' => '₺960']]);
        same(96000, (int) Db::value('SELECT price FROM items WHERE id = ?', [$id]));
        same(87000, (int) Db::value('SELECT old_price FROM price_history WHERE item_id = ?', [$id]));
        check((bool) Db::value("SELECT 1 FROM audit_log WHERE action = 'menu.price' AND summary LIKE '%₺870 → ₺960%'"), 'activity log');
    },

    'bulk: +10 % rounded to ₺10 (Figma M7 example)' => function (): void {
        same(96000, Menu::adjusted(87000, 10, 1000));
        same(396000, Menu::adjusted(360000, 10, 1000));
        same(109000, Menu::adjusted(99000, 10, 1000));
        same(48000, Menu::adjusted(45000, 6.7, 1000));
    },

    'daily stock: sold out when nothing is left' => function () use ($item): void {
        $id = $item();
        Menu::quick([$id => ['daily_stock' => '2']]);
        $row = Db::row('SELECT * FROM items WHERE id = ?', [$id]);
        check(Menu::state($row, 1.0)['orderable'] && Menu::state($row, 1.0)['left'] === 1, 'one left');
        check(Menu::state($row, 2.0)['soldout'] && !Menu::state($row, 2.0)['orderable'], 'sold out at 0');
        Menu::quick([$id => ['daily_stock' => '']]);
        same(null, Db::value('SELECT daily_stock FROM items WHERE id = ?', [$id]), 'empty = unlimited');
    },

    'sold today comes from sent order lines of the business day' => function () use ($item): void {
        $id = $item();
        Clock::freeze(strtotime('2026-09-28 14:00') * 1000);
        $order = Db::save('orders', ['no' => 1, 'day' => '2026-09-28', 'channel' => 'table', 'status' => 'open', 'opened_at' => Clock::ms()]);
        foreach (['sent' => 2, 'void' => 5, 'new' => 1] as $status => $qty) {
            Db::save('order_items', ['order_id' => $order, 'item_id' => $id, 'name' => 'Adana', 'qty' => $qty, 'unit_price' => 87000, 'status' => $status, 'created_at' => Clock::ms()]);
        }
        same(2.0, Menu::soldToday()[$id] ?? 0.0);
    },

    'floor: next free table number, permanent 6-character code, website link format' => function (): void {
        Settings::set('profile.website', 'https://basiliccaferestaurant.com');
        $area = Floor::saveArea(['names' => ['tr' => 'Bahçe', 'en' => 'Garden', 'fa' => 'حیاط']]);
        $t1 = Floor::addTable($area);
        $t2 = Floor::addTable($area);
        same('2', Db::value('SELECT number FROM tables WHERE id = ?', [$t2]));
        $t = Floor::table($t1);
        check((bool) preg_match('/^(?=[a-z0-9]*[a-z])[a-z0-9]{6}$/', $t['code']), 'code format');
        same('https://basiliccaferestaurant.com/menu?masa=' . $t['code'], Floor::qrUrl($t));
        Floor::saveTable($t1, ['number' => '7']);
        same($t['code'], Floor::table($t1)['code'], 'renumbering keeps the code');
        same(33, count(Qr::matrix(Floor::qrUrl($t))), 'a 51-byte table link needs a version 4 QR (33 modules)');
    },
];
