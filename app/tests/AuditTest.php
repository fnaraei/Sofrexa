<?php
/**
 * The scenarios of the 2026-09-28 audit (F01–F25, S02–S04), each with the result it must have: money taken, stock,
 * reports and sync stay right through splits, discounts, voids, shifts, business-day edges and sync batches.
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Money, Net, Settings};
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Modules\Reports\{Accountant, Reports, StockReport};
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Print\Spooler;
use Sofrexa\Setup\{Nightly, Seed};
use Sofrexa\Sync\Apply;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $a = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $b = Menu::saveItem(['names' => ['tr' => 'Makarna'], 'category_id' => $cat, 'price' => '100', 'available' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $area = Floor::saveArea(['names' => ['tr' => 'Salon']]);
    $t1 = Floor::addTable($area);
    $t2 = Floor::addTable($area);
    Shifts::open(['TRY' => 0]);
    return compact('boss', 'cat', 'a', 'b', 't1', 't2');
};
// one kg of flour at ₺10 per pizza, 10 kg on the shelf
$flour = static function (string $item): string {
    $s = Stock::saveItem(['name' => 'Un', 'unit' => 'kg']);
    Stock::document('purchase', [['stock_item_id' => $s, 'qty' => '10', 'unit_price' => '10']]);
    Stock::setRecipe('item', $item, [['stock_item_id' => $s, 'qty' => '1']]);
    return $s;
};
$at = static fn(string $local): int => (int) strtotime($local) * 1000;
$fails = static function (callable $fn, string $what): void {
    try {
        $fn();
    } catch (\InvalidArgumentException) {
        return;
    }
    throw new LogicException('accepted: ' . $what);
};

return [
    'F01 a split cannot leave more paid than the bill that stays; discounts follow the dishes' => function () use ($setup, $fails): void {
        $s = $setup();
        $o = Orders::forTable($s['t1']);
        $la = Orders::addItem($o, $s['a']);
        Orders::addItem($o, $s['b']);
        Orders::pay($o, [['method' => 'card', 'amount' => 15000]], null, false);
        $fails(static fn() => Orders::split($o, [$la]), '₺150 paid, ₺100 would stay');
        same(0, (int) Db::value('SELECT COUNT(*) FROM orders WHERE parent_id = ?', [$o]), 'nothing split');

        $o2 = Orders::forTable($s['t2']);
        $l2 = Orders::addItem($o2, $s['a']);
        Orders::addItem($o2, $s['b']);
        Orders::discount($o2, 'amount', 4000);
        Orders::pay($o2, [['method' => 'card', 'amount' => 5000]], null, false);
        $child = Orders::split($o2, [$l2]);
        same([10000, 2000, 8000, 5000], array_map('intval', array_values(array_intersect_key(Orders::get($o2), array_flip(['subtotal', 'discount', 'total', 'paid'])))), 'half of the ₺40 stays');
        same(8000, (int) Orders::get($child)['total'], 'half of the ₺40 goes with the dish');
        Orders::pay($o2, [['method' => 'card', 'amount' => 3000]], null, false);
        Orders::pay($child, [['method' => 'card', 'amount' => 8000]], null, false);
        same(16000, (int) Db::value('SELECT SUM(amount) FROM payments WHERE order_id IN (?, ?)', [$o2, $child]), '₺200 − ₺40 taken, not more');
    },

    'F02 the till reads 36,82 · 36.82 · ۳۶٫۸۲ alike and refuses what is not a number' => function (): void {
        foreach (['36,82', '36.82', '۳۶٫۸۲', '٣٦٫٨٢', ' 36,82 ', '£36.82'] as $v) {
            same(36.82, Money::number($v), $v);
        }
        same(1234.5, Money::number('1.234,50'));
        same(1234.0, Money::number('1.234'));
        same(1234567.0, Money::number('1.234.567'));
        same(1234.5, Money::number('1234.5'));
        same(1234.5, Money::number('۱٬۲۳۴٫۵'));
        foreach (['1,2,3', '12.5.3', 'abc', '', '1.2,3.4'] as $v) {
            same(null, Money::number($v), $v);
        }
        same(800000, Money::parse('₺8.000 +'), 'admin forms stay lenient');
    },

    'F07 F08 a line voids once, and what comes back is what it really took' => function () use ($setup, $flour, $fails): void {
        $s = $setup();
        $un = $flour($s['a']);
        $o = Orders::forTable($s['t1']);
        $l = Orders::addItem($o, $s['a']);
        Orders::send($o);
        same(9.0, Stock::onHand($un));
        // the recipe changes after the dish was sent: the void must give back flour, not the new ingredient
        $cheese = Stock::saveItem(['name' => 'Peynir', 'unit' => 'kg']);
        Stock::setRecipe('item', $s['a'], [['stock_item_id' => $cheese, 'qty' => '2']]);
        Orders::voidLine($l, 'vazgeçti');
        $fails(static fn() => Orders::voidLine($l, 'tekrar'), 'a second void');
        Orders::settleVoid($l, 'returned');
        $fails(static fn() => Orders::settleVoid($l, 'returned'), 'a second give-back');
        same(10.0, Stock::onHand($un), 'the flour it took comes back once');
        same(0.0, Stock::onHand($cheese), 'nothing of the new recipe');
    },

    'a cooked dish that is cancelled is waste, and can go to another table or to a staff member' => function () use ($setup, $flour, $at): void {
        $s = $setup();
        $un = $flour($s['a']);
        Clock::freeze($at('2026-09-28 20:00'));
        $o = Orders::forTable($s['t1']);
        $l = Orders::addItem($o, $s['a'], 2);
        Orders::send($o);
        Db::save('order_items', ['id' => $l, 'status' => 'ready', 'ready_at' => Clock::ms()]);
        Orders::voidLine($l, 'masa vazgeçti', 1);
        $void = (string) Db::value("SELECT id FROM order_items WHERE void_of = ?", [$l]);
        same('waste', Db::value('SELECT void_stock FROM order_items WHERE id = ?', [$void]), 'ready: waste at once, no question');
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'void' AND done_at IS NULL"));
        same(8.0, Stock::onHand($un), 'the cooked pizza does not go back to the shelf');
        [$f, $t] = Clock::dayRange('2026-09-28');
        same(1000, Reports::waste($f, $t));
        // table 2 orders the same pizza: the cooked one goes there
        $o2 = Orders::forTable($s['t2']);
        $new = Orders::reuseVoided($void, $o2);
        $n = Orders::line($new);
        same(['ready', 10000], [$n['status'], (int) $n['unit_price']]);
        same(10000, (int) Orders::get($o2)['total']);
        same(0, Reports::waste($f, $t), 'not waste any more');
        same(8.0, Stock::onHand($un), 'no new flour taken');
        Orders::pay($o2, [['method' => 'card', 'amount' => 10000]], null, false);
        same(1000, Reports::costOfSales($f, $t), 'its flour is the cost of table 2’s sale');

        // only sent (the kitchen may not have started): the till asks
        $o3 = Orders::forTable($s['t1']);
        Orders::send($o3);
        $l3 = Orders::addItem($o3, $s['a']);
        Orders::send($o3);
        Orders::voidLine($l3, 'vazgeçti');
        same('pending', Db::value('SELECT void_stock FROM order_items WHERE id = ?', [$l3]));
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'void' AND ref_type = 'order_item' AND ref_id = ? AND role = 'cashier'", [$l3]));
        // a waiter wants it: charged to them
        Orders::chargeVoidedToStaff($l3, $s['boss']);
        same(['staff', 10000], [Db::value('SELECT void_stock FROM order_items WHERE id = ?', [$l3]), (int) Db::value("SELECT total FROM payroll WHERE kind = 'charge' AND user_id = ?", [$s['boss']])]);
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'void' AND done_at IS NULL"), 'the question is answered');
        same([], array_filter(Finance::entries($f, $t), static fn(array $e): bool => $e['source'] === 'payroll'), 'a charge is not money paid out');
    },

    'F09 a bill paid across two shifts is a sale of the shift that settled it' => function () use ($setup): void {
        $s = $setup();
        $s1 = Shifts::currentId();
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        Orders::pay($o, [['method' => 'card', 'amount' => 5000]], null, false);
        Shifts::close(['TRY' => 0]);
        $s2 = Shifts::open(['TRY' => 0]);
        Orders::pay($o, [['method' => 'card', 'amount' => 5000]], null, false);
        same([0, 10000], [(int) Shifts::summary($s1)['orders']['total'], (int) Shifts::summary($s2)['orders']['total']]);
        same([5000, 5000], [(int) Shifts::summary($s1)['methods']['card'], (int) Shifts::summary($s2)['methods']['card']], 'the money where it was taken');
        same([0, 10000], [Reports::z($s1)['sales'], Reports::z($s2)['sales']]);
    },

    'F10 F11 F12 the accountant, the VAT, dated documents and food cost agree on the business day' => function () use ($setup, $flour, $at): void {
        $s = $setup();
        $flour($s['a']);
        Clock::freeze($at('2026-09-27 23:00'));
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        Orders::send($o);
        Clock::freeze($at('2026-09-28 14:00'));
        Orders::pay($o, [['method' => 'card', 'amount' => 10000]], null, false);
        [$f, $t] = Clock::dayRange('2026-09-28');
        same(['10' => [10000, 909]], Reports::vatByRate($f, $t));
        $a = Accountant::tables($f, $t, ['sales']);
        same([['28.09.2026', 1, 100.0, 0.0, 9.09, 90.91]], $a['sales'][2], 'the day it was settled, its VAT');
        $k = Reports::kpis($f, $t);
        same([10000, 1000], [$k['sales'], $k['cost']], 'the flour sent last night is this sale’s cost');

        Finance::add(['kind' => 'expense', 'category' => 'rent', 'amount' => '100', 'day' => '2026-09-28', 'method' => 'bank']);
        [$f27, $t27] = Clock::dayRange('2026-09-27');
        $manual = static fn(array $rows): array => array_column(array_filter($rows, static fn(array $e): bool => $e['source'] === 'manual'), 'day');
        same([], $manual(Finance::entries($f27, $t27)), 'a bill dated the 28th is not in the 27th');
        same(['2026-09-28'], $manual(Finance::entries($f, $t)));
    },

    'F13 last month’s stock value does not change with today’s price' => function () use ($setup, $at): void {
        $setup();
        Clock::freeze($at('2026-09-27 14:00'));
        $un = Stock::saveItem(['name' => 'Un', 'unit' => 'kg']);
        Stock::document('purchase', [['stock_item_id' => $un, 'qty' => '10', 'unit_price' => '10']]);
        [$f, $t] = Clock::dayRange('2026-09-27');
        Clock::freeze($at('2026-09-28 14:00'));
        Stock::document('purchase', [['stock_item_id' => $un, 'qty' => '10', 'unit_price' => '30']]);
        $row = StockReport::moves($f, $t)[0];
        same([10.0, 10000], [$row['end'], $row['value']]);
        same(40000, StockReport::moves(...array_values(array_intersect_key(Reports::period('today'), array_flip(['from', 'to']))))[0]['value'], 'today at today’s average');
    },

    'F14 F15 removing a discount removes all of it; a percentage follows the bill' => function () use ($setup): void {
        $s = $setup();
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        $lb = Orders::addItem($o, $s['b']);
        Orders::discount($o, 'amount', 15000);
        Orders::updateLine($lb, 0);
        same(10000, (int) Orders::get($o)['discount'], 'capped by the bill');
        Orders::clearDiscount($o);
        same(0, (int) Orders::get($o)['discount']);
        Orders::addItem($o, $s['b']);
        same(0, (int) Orders::get($o)['discount'], 'nothing comes back when the bill grows');

        $o2 = Orders::forTable($s['t2']);
        Orders::addItem($o2, $s['a']);
        Orders::discount($o2, 'pct', 10);
        same(1000, (int) Orders::get($o2)['discount']);
        Orders::addItem($o2, $s['b']);
        same(2000, (int) Orders::get($o2)['discount'], '10% of ₺200');
    },

    'F16 the daily stock counts what waits unsent in bills and is checked again when sent' => function () use ($setup, $fails): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 1]);
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        $fails(static fn() => Orders::addItem($o, $s['a']), 'a second portion of 1');
        $o2 = Orders::forTable($s['t2']);
        $fails(static fn() => Orders::addItem($o2, $s['a']), 'another bill takes the reserved one');
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 2]);
        Orders::addItem($o2, $s['a']);
        Db::save('items', ['id' => $s['a'], 'daily_stock' => 1]);
        Orders::send($o);
        $fails(static fn() => Orders::send($o2), 'sent beyond the daily stock');
        same(1.0, Menu::soldToday()[$s['a']]);
    },

    'F17 a bill discounted to ₺0 closes without a made-up payment' => function () use ($setup): void {
        $s = $setup();
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        Orders::discount($o, 'pct', 100);
        $r = Orders::pay($o, [['method' => 'cash', 'amount' => 0]], null, false);
        same(true, $r['paid']);
        $x = Orders::get($o);
        same(['paid', 0, 0], [$x['status'], (int) $x['total'], count($x['payments'])]);
        same(Shifts::currentId(), $x['closed_shift_id']);
    },

    'F18 a split table moves with all its bills' => function () use ($setup): void {
        $s = $setup();
        $o = Orders::forTable($s['t1']);
        $la = Orders::addItem($o, $s['a']);
        Orders::addItem($o, $s['b']);
        $child = Orders::split($o, [$la]);
        Orders::moveTable($o, $s['t2']);
        same([$s['t2'], $s['t2']], [Orders::get($o)['table_id'], Orders::get($child)['table_id']]);
        Orders::moveTable($child, $s['t1']);
        same([$s['t1'], $s['t1']], [Orders::get($o)['table_id'], Orders::get($child)['table_id']], 'from either bill');
    },

    'F19 two edits at the same moment end the same on both copies (the till’s wins)' => function () use ($setup): void {
        $s = $setup();
        $row = Db::row('SELECT * FROM items WHERE id = ?', [$s['a']]);
        $t = (int) $row['updated_at'] + 5;
        $pc = ['price' => 11000, 'updated_at' => $t] + $row;
        $web = ['price' => 12000, 'updated_at' => $t] + $row;
        // on the PC (its own edit in the database), the web copy's edit arrives
        App::setConfig('role', 'pc');
        Db::update('items', ['price' => 11000, 'updated_at' => $t], 'id = ?', [$s['a']]);
        Apply::rows([['t' => 'items', 'r' => $web]]);
        same(11000, (int) Db::value('SELECT price FROM items WHERE id = ?', [$s['a']]), 'the PC keeps its own');
        // on the web copy (its own edit in the database), the PC's edit arrives
        App::setConfig('role', 'web');
        Db::update('items', ['price' => 12000, 'updated_at' => $t], 'id = ?', [$s['a']]);
        Apply::rows([['t' => 'items', 'r' => $pc]]);
        same(11000, (int) Db::value('SELECT price FROM items WHERE id = ?', [$s['a']]), 'the web copy takes the PC’s');
        App::setConfig('role', 'pc');
    },

    'F22 the VAT parts add up to the bill' => function () use ($setup): void {
        $s = $setup();
        Db::save('items', ['id' => $s['a'], 'price' => 5, 'vat_rate' => 10]);
        Db::save('items', ['id' => $s['b'], 'price' => 5, 'vat_rate' => 20]);
        $o = Orders::forTable($s['t1']);
        Orders::addItem($o, $s['a']);
        Orders::addItem($o, $s['b']);
        Orders::discount($o, 'amount', 1);
        same(9, (int) Orders::get($o)['total']);
        same(9, array_sum(array_column(Orders::vat($o), 0)));
        same(['10' => [4, 0], '20' => [5, 1]], Orders::vat($o), 'the kuruş left over goes to the larger remainder (tie: the higher rate)');
    },

    'kitchen report: dishes over the late minutes are counted (numbers bound as text next to a difference)' => function () use ($setup, $at): void {
        $s = $setup();
        Settings::set('order.late_minutes', 20);
        Clock::freeze($at('2026-09-28 20:00'));
        $o = Orders::forTable($s['t1']);
        $slow = Orders::addItem($o, $s['a']);
        $fast = Orders::addItem($o, $s['b']);
        Orders::send($o);
        Db::save('order_items', ['id' => $slow, 'status' => 'ready', 'ready_at' => Clock::ms() + 25 * 60_000]);
        Db::save('order_items', ['id' => $fast, 'status' => 'ready', 'ready_at' => Clock::ms() + 5 * 60_000]);
        [$f, $t] = Clock::dayRange('2026-09-28');
        $k = Reports::kitchen($f, $t);
        same([50.0, 1], [(float) $k['late_pct'], $k['late_orders']]);
    },

    'F23 today is compared with the same hours yesterday' => function () use ($at): void {
        Clock::freeze($at('2026-09-28 14:00'));
        $p = Reports::period('today');
        same(['2026-09-27 05:00', '2026-09-27 14:00'], [date('Y-m-d H:i', intdiv($p['prev_from'], 1000)), date('Y-m-d H:i', intdiv($p['prev_to'], 1000))]);
    },

    'F24 required options are required on every channel' => function () use ($setup, $fails): void {
        $s = $setup();
        $g = Db::save('modifier_groups', ['names' => ['tr' => 'Boy'], 'min_sel' => 1, 'max_sel' => 1]);
        $big = Db::save('modifiers', ['group_id' => $g, 'names' => ['tr' => 'Büyük'], 'price' => 1000]);
        $small = Db::save('modifiers', ['group_id' => $g, 'names' => ['tr' => 'Küçük'], 'price' => 0]);
        Db::save('item_modifier_groups', ['group_id' => $g, 'item_id' => $s['a']]);
        $other = Db::save('modifiers', ['group_id' => Db::save('modifier_groups', ['names' => ['tr' => 'Sos'], 'min_sel' => 0, 'max_sel' => 0]), 'names' => ['tr' => 'Acı'], 'price' => 500]);
        $o = Orders::forTable($s['t1']);
        $fails(static fn() => Orders::addItem($o, $s['a']), 'no size chosen');
        $fails(static fn() => Orders::addItem($o, $s['a'], 1, [$big, $small]), 'two sizes');
        $fails(static fn() => Orders::addItem($o, $s['a'], 1, [$big, $other]), 'an option of another dish');
        Orders::addItem($o, $s['a'], 1, [$big]);
        same(11000, (int) Orders::get($o)['total']);
    },

    'F25 the Cloudflare header is believed only from Cloudflare' => function (): void {
        $keep = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '127.0.0.1';
        same('203.0.113.9', Net::clientIp(), 'written by anyone reaching the server directly');
        $_SERVER['REMOTE_ADDR'] = '172.64.10.20';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
        same('198.51.100.7', Net::clientIp(), 'from a Cloudflare edge');
        check(Net::inCidr('2606:4700::1', '2606:4700::/32') && !Net::inCidr('2001:db8::1', '2606:4700::/32') && Net::inCidr('192.168.1.40', '192.168.1.0/24'), 'IPv4 and IPv6 ranges');
        $_SERVER = $keep;
    },

    'F05 private files are not served, whatever the path looks like' => function (): void {
        $up = App::storage('uploads');
        @mkdir($up . '/private/receipts', 0775, true);
        @mkdir($up . '/menu', 0775, true);
        file_put_contents($up . '/private/receipts/fis.txt', 'private');
        file_put_contents($up . '/menu/pizza.jpg', 'photo');
        check(\Sofrexa\Core\Media::publicFile('/media/menu/pizza.jpg') !== null, 'a menu photo');
        foreach (['/media/private/receipts/fis.txt', '/media/%2e/private/receipts/fis.txt', '/media/./private/receipts/fis.txt', '/media/menu/../private/receipts/fis.txt',
            '/media/menu/%2e%2e/private/receipts/fis.txt', '/media/../config.php'] as $p) {
            same(null, \Sofrexa\Core\Media::publicFile($p), $p);
        }
    },

    'F06 a new phone at an approved table waits for the waiter once' => function () use ($setup, $as): void {
        $s = $setup();
        $t = Floor::table($s['t1']);
        $as(null);
        $_SESSION = [];
        $line = [['item' => $s['a'], 'qty' => 1, 'mods' => [], 'note' => '']];
        [$o1] = QrOrders::submit($t, $line);
        same('pending', Db::value('SELECT status FROM orders WHERE id = ?', [$o1]));
        QrOrders::accept($o1, $s['boss']);
        [$o2] = QrOrders::submit($t, $line);
        check(Db::value('SELECT status FROM orders WHERE id = ?', [$o2]) !== 'pending', 'the approved phone orders on its own');
        $_SESSION = []; // another phone (a photo of the table card)
        [$o3] = QrOrders::submit($t, $line);
        same('pending', Db::value('SELECT status FROM orders WHERE id = ?', [$o3]), 'asked once');
        same(0, (int) Db::value("SELECT COUNT(*) FROM order_items WHERE src_order_id = ? AND status = 'sent'", [$o3]));
        QrOrders::accept($o3, $s['boss']);
        [$o4] = QrOrders::submit($t, $line);
        check(Db::value('SELECT status FROM orders WHERE id = ?', [$o4]) !== 'pending', 'then trusted too');
        $_SESSION = [];
    },

    'F04 a web order is taken in only when all its lines have arrived' => function () use ($setup, $as): void {
        $s = $setup();
        Settings::set('qr.require_first_approval', false);
        $t = Floor::table($s['t1']);
        App::setConfig('role', 'web');
        App::setConfig('sync.key', str_repeat('k', 32));
        \Sofrexa\Sync\Status::markOk();
        $as(null);
        $_SESSION = [];
        Db::exec('DELETE FROM sync_outbox');
        [$o] = QrOrders::submit($t, [['item' => $s['a'], 'qty' => 1, 'mods' => [], 'note' => ''], ['item' => $s['b'], 'qty' => 1, 'mods' => [], 'note' => '']]);
        $all = Apply::collect(Db::rows('SELECT * FROM sync_outbox ORDER BY seq'));
        $lines = array_values(array_filter($all, static fn(array $r): bool => $r['t'] === 'order_items'));
        $rest = array_values(array_filter($all, static fn(array $r): bool => $r['t'] !== 'order_items'));
        // the till's copy: the order without its lines first (the batch broke between them)
        Db::exec('DELETE FROM order_items WHERE order_id = ?', [$o]);
        Db::exec('DELETE FROM orders WHERE id = ?', [$o]);
        Db::exec('DELETE FROM qr_sessions');
        App::setConfig('role', 'pc');
        $as($s['boss']);
        Apply::rows($rest);
        Apply::rows([$lines[0]]);
        QrOrders::intake();
        same(['pending', null], array_values(Db::row('SELECT status, intake_at FROM orders WHERE id = ?', [$o])), 'waits for its other line');
        Apply::rows([$lines[1]]);
        QrOrders::intake();
        same('open', Db::value('SELECT status FROM orders WHERE id = ?', [$o]));
        same(0, (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status = 'new'", [$o]), 'both lines went to the kitchen');
        $_SESSION = [];
    },

    'F21 a ticket waits for its printer as long as it takes; a drawer kick does not' => function () use ($setup, $at): void {
        $setup();
        Clock::freeze($at('2026-09-28 20:00'));
        Settings::set('printer.kitchen', ['driver' => 'tcp', 'target' => '127.0.0.1:1', 'width' => 80]);
        Spooler::print('kitchen', 'kitchen', 'MASA 1');
        for ($i = 0; $i < 25; $i++) {
            Spooler::work();
            Clock::freeze(Clock::ms() + 31_000);
        }
        same(['retry', 1], [Db::value("SELECT status FROM print_jobs WHERE kind = 'kitchen'"), Spooler::pending()], 'never dropped');
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'printer' AND done_at IS NULL"), 'the till is told');
        Settings::set('printer.kitchen', ['driver' => 'file', 'target' => '', 'width' => 80]);
        Clock::freeze(Clock::ms() + 31_000);
        same(1, Spooler::work(), 'printed when the printer is back');
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'printer' AND done_at IS NULL"), 'the notice closes');
        Settings::set('printer.cashier', ['driver' => 'tcp', 'target' => '127.0.0.1:1', 'width' => 80]);
        Spooler::print('cashier', 'drawer', 'x');
        Spooler::work();
        Clock::freeze(Clock::ms() + 61_000);
        Spooler::work();
        same('expired', Db::value("SELECT status FROM print_jobs WHERE kind = 'drawer'"), 'the drawer does not spring open later');
    },

    'S02 PIN sign-in is offered on the web copy during emergency mode' => function () use ($setup): void {
        $setup();
        App::setConfig('role', 'web');
        App::setConfig('pin_networks', ['127.0.0.1']);
        check(!Auth::pinAllowedFrom('198.51.100.20'), 'not from outside');
        Db::exec('INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)', ['emergency', json_encode(['ip' => '198.51.100.20', 'at' => Clock::ms()])]);
        check(\Sofrexa\Sync\Emergency::on() && Auth::pinAllowedFrom('198.51.100.20'), 'the restaurant’s address during emergency mode');
        App::setConfig('role', 'pc');
        App::setConfig('pin_networks', []);
    },

    'S04 the nightly jobs catch up after a night the PC was off, and only a finished job counts' => function () use ($setup, $at): void {
        $setup();
        Settings::set('backup.nightly', false);
        Settings::set('backup.hour', 3);
        Clock::freeze($at('2026-09-28 02:00'));
        check(!Nightly::due(), 'not before the hour');
        Clock::freeze($at('2026-09-28 10:30'));
        check(Nightly::due(), 'switched on in the morning: it catches up');
        $broken = true;
        Nightly::register('audit', static function () use (&$broken): void {
            if ($broken) {
                throw new RuntimeException('disk full');
            }
        });
        Nightly::run(static fn() => null);
        check(!Nightly::due(), 'a failed run waits an hour');
        same('2026-09-28', \Sofrexa\Sync\Client::state('nightly:loyalty'), 'what worked is kept');
        Clock::freeze($at('2026-09-28 11:31'));
        check(Nightly::due(), 'then it is tried again');
        $broken = false;
        Nightly::run(static fn() => null);
        check(!Nightly::due(), 'done for the day');
        same('2026-09-28', \Sofrexa\Sync\Client::state('nightly_day'));
        Nightly::register('audit', static fn() => null);
    },
];
