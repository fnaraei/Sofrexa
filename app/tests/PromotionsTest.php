<?php
/** Promotions: days, hours (also past midnight), dates, channels, the best one, and the price on the order line. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db};
use Sofrexa\Modules\Menu\{Floor, Menu, Promotions};
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$boss]));
    $pizzas = Db::save('categories', ['names' => ['tr' => 'Pizzalar'], 'station' => 'kitchen']);
    $drinks = Db::save('categories', ['names' => ['tr' => 'İçecekler'], 'station' => 'bar']);
    $margarita = Menu::saveItem(['names' => ['tr' => 'Margarita'], 'category_id' => $pizzas, 'price' => '650', 'available' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $beer = Menu::saveItem(['names' => ['tr' => 'Efes'], 'category_id' => $drinks, 'price' => '180', 'available' => 1, 'show_qr' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    Shifts::open(['TRY' => 0]);
    return compact('pizzas', 'drinks', 'margarita', 'beer', 'table');
};
// 2026-09-29 is a Tuesday; the tests run in the app's time zone
$at = static fn(string $local): int => (new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get())))->getTimestamp() * 1000;

return [
    'days, hours, dates and channels' => function () use ($setup, $at): void {
        $s = $setup();
        $happy = Promotions::save(['name' => 'Happy hour', 'pct' => '20', 'scope' => 'categories', 'targets' => [$s['drinks']], 'days' => [1, 2, 3, 4, 5],
            'time_from' => '17:00', 'time_to' => '19:00', 'channels' => ['table']]);
        $p = Promotions::get($happy);
        check(Promotions::runs($p, $at('2026-09-29 17:30')), 'Tuesday 17:30');
        check(!Promotions::runs($p, $at('2026-09-29 19:00')), 'ends at 19:00');
        check(!Promotions::runs($p, $at('2026-10-03 17:30')), 'not on Saturday');
        $late = Promotions::get(Promotions::save(['name' => 'Gece', 'pct' => 10, 'days' => [5], 'time_from' => '22:00', 'time_to' => '02:00', 'channels' => ['table']]));
        check(Promotions::runs($late, $at('2026-10-02 23:00')), 'Friday 23:00');
        check(Promotions::runs($late, $at('2026-10-03 01:30')), 'Friday night, 01:30 on Saturday');
        check(!Promotions::runs($late, $at('2026-10-02 01:30')), 'not Thursday night');
        $dated = Promotions::get(Promotions::save(['name' => 'Açılış', 'pct' => 15, 'days' => [1, 2, 3, 4, 5, 6, 7], 'date_from' => '2026-10-01', 'date_to' => '2026-10-07', 'channels' => ['online']]));
        check(!Promotions::runs($dated, $at('2026-09-30 12:00')) && Promotions::runs($dated, $at('2026-10-07 23:00')), 'date range, last day included');
        same('ended', Promotions::state($dated, $at('2026-10-08 10:00')));

        Promotions::flush();
        $beer = Menu::get($s['beer']);
        same($happy, Promotions::best($beer, 'table', $at('2026-09-29 18:00'))['id']);
        same(null, Promotions::best($beer, 'online', $at('2026-09-29 18:00')), 'table only');
        same(null, Promotions::best(Menu::get($s['margarita']), 'table', $at('2026-09-29 18:00')), 'drinks only');
        same(14400, Promotions::price(18000, 20));
        same(52000, Promotions::price(65000, 20));
        same(74000, Promotions::price(87000, 15), '₺739,50 → ₺740: whole lira');
        same(null, Promotions::best(['price' => 0] + $beer, 'table', $at('2026-09-29 18:00')), 'priced by options only');
        same(null, Promotions::best(['price' => 200] + $beer, 'table', $at('2026-09-29 18:00')), '%20 of ₺2 rounds back to ₺2');
        // the list wording (Figma PR1): Turkish suffix after the hour, and the day it starts next
        same(['a', 'ye', 'ya', 'e', 'a'], array_map([Promotions::class, 'trTimeSuffix'], ['19:00', '17:00', '16:00', '13:00', '12:30']));
        \Sofrexa\Core\I18n::set('tr');
        same('19:00’a kadar', Promotions::hint($p, $at('2026-09-29 18:00')));
        same('Bugün 17:00', Promotions::hint($p, $at('2026-09-29 10:00')));
        same('Pazartesi', Promotions::hint($p, $at('2026-10-03 10:00')));
        foreach ([['name' => '', 'pct' => 0, 'days' => [], 'channels' => []], ['name' => 'X', 'pct' => 10, 'scope' => 'items', 'days' => [1], 'channels' => ['table'], 'time_from' => '10:00']] as $bad) {
            try {
                Promotions::save($bad);
                throw new LogicException('bad promotion saved');
            } catch (\Sofrexa\Core\ValidationError) {
            }
        }
    },

    'the order line takes the promotion when the dish is added' => function () use ($setup, $at): void {
        $s = $setup();
        Promotions::save(['name' => 'Happy hour', 'pct' => 20, 'scope' => 'items', 'targets' => [$s['beer']], 'days' => [2], 'time_from' => '17:00', 'time_to' => '19:00', 'channels' => ['table']]);
        Promotions::save(['name' => 'Salı', 'pct' => 30, 'scope' => 'categories', 'targets' => [$s['drinks']], 'days' => [2], 'channels' => ['table']]);
        Clock::freeze($at('2026-09-29 18:00'));
        Promotions::flush();
        $o = Orders::forTable($s['table']);
        $l = Orders::line(Orders::addItem($o, $s['beer'], 2));
        same([12600, 18000], [(int) $l['unit_price'], (int) $l['list_price']], 'the best one: %30');
        check((bool) $l['promo_id'], 'promotion kept on the line');
        same(['Salı', 30, 10800], [Promotions::onLine($l)['name'], Promotions::onLine($l)['pct'], Promotions::onLine($l)['saving']], 'OrderLine "Salı %30 · −₺108"');
        same(25200, (int) Orders::get($o)['total']);
        Clock::freeze($at('2026-09-30 18:00'));
        Promotions::flush();
        $l2 = Orders::line(Orders::addItem($o, $s['beer'], 1));
        check($l2['id'] !== $l['id'], 'a new line at the full price');
        same([18000, null], [(int) $l2['unit_price'], $l2['list_price']]);
    },
];
