<?php
/** Stock: purchase with average cost, multi-level recipes, deduction on sale and give-back on void, count, shopping list. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Db};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $uid = Seed::user('Depo Veli', 'manager', '5656');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
    $sup = Stock::saveSupplier(['name' => 'Et Dünyası']);
    $meat = Stock::saveItem(['name' => 'Kıyma', 'unit' => 'kg', 'category' => 'Et', 'min_qty' => '5', 'supplier_id' => $sup]);
    $pepper = Stock::saveItem(['name' => 'Pul biber', 'unit' => 'kg', 'category' => 'Baharat', 'min_qty' => '0,5']);
    $onion = Stock::saveItem(['name' => 'Soğan', 'unit' => 'kg', 'category' => 'Sebze', 'min_qty' => '2']);
    $mix = Stock::saveItem(['name' => 'Adana harcı', 'unit' => 'kg', 'kind' => 'semi', 'category' => 'Hazırlık']);
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $adana = Menu::saveItem(['names' => ['tr' => 'Adana Kebap'], 'category_id' => $cat, 'price' => '870', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    return compact('sup', 'meat', 'pepper', 'onion', 'mix', 'adana', 'table');
};

return [
    'purchase: stock goes up, average cost is weighted' => function () use ($setup): void {
        ['sup' => $sup, 'meat' => $meat] = $setup();
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '10', 'unit_price' => '400']], ['supplier_id' => $sup, 'doc_no' => '4471']);
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '10', 'total' => '5000']], ['supplier_id' => $sup]);
        same(20.0, Stock::onHand($meat));
        same(45000.0, (float) Db::value('SELECT avg_cost FROM stock_items WHERE id = ?', [$meat]), '(400 + 500) / 2 per kg');
    },

    'recipe through a semi-finished item: cost on the menu item, deduction on send, give-back on void' => function () use ($setup): void {
        ['meat' => $meat, 'pepper' => $pepper, 'onion' => $onion, 'mix' => $mix, 'adana' => $adana, 'table' => $table] = $setup();
        Stock::document('purchase', [
            ['stock_item_id' => $meat, 'qty' => '10', 'unit_price' => '400'],
            ['stock_item_id' => $pepper, 'qty' => '1', 'unit_price' => '300'],
            ['stock_item_id' => $onion, 'qty' => '5', 'unit_price' => '30'],
        ]);
        Stock::setRecipe('stock', $mix, [['stock_item_id' => $meat, 'qty' => '0,9'], ['stock_item_id' => $pepper, 'qty' => '0,02'], ['stock_item_id' => $onion, 'qty' => '0,1']]);
        Stock::setRecipe('item', $adana, [['stock_item_id' => $mix, 'qty' => '0,2']]);
        // 0,2 kg of mix = 0,18 kg meat (72) + 0,004 kg pepper (1,2) + 0,02 kg onion (0,6) = ₺73,80
        same(7380, (int) Db::value('SELECT cost FROM items WHERE id = ?', [$adana]));
        $o = Orders::forTable($table);
        $l = Orders::addItem($o, $adana, 2);
        Orders::send($o);
        same(9.64, Stock::onHand($meat), '2 portions take 0,36 kg meat');
        Orders::voidLine($l, 'müşteri vazgeçti', 1);
        same(9.82, Stock::onHand($meat), 'one portion comes back');
        try {
            Stock::setRecipe('stock', $meat, [['stock_item_id' => $mix, 'qty' => 1]]);
            Stock::setRecipe('stock', $mix, [['stock_item_id' => $mix, 'qty' => 1]]);
            throw new LogicException('loop accepted');
        } catch (\Sofrexa\Core\ValidationError) {
        }
    },

    'waste and count adjust the stock; the shopping list shows what is under minimum' => function () use ($setup): void {
        ['meat' => $meat, 'onion' => $onion, 'sup' => $sup] = $setup();
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '6', 'unit_price' => '400'], ['stock_item_id' => $onion, 'qty' => '5', 'unit_price' => '30']]);
        Stock::document('waste', [['stock_item_id' => $meat, 'qty' => '0,5', 'reason' => 'Bozuldu']]);
        same(5.5, Stock::onHand($meat));
        [, $diffs] = Stock::count([$meat => '4,5', $onion => '5']);
        same(-1.0, $diffs[$meat]['diff']);
        same(-40000, $diffs[$meat]['value']);
        same(4.5, Stock::onHand($meat));
        $list = Stock::shoppingList();
        same('Kıyma', $list['Et Dünyası'][0]['name']);
        same(6.0, (float) $list['Et Dünyası'][0]['suggest'], 'up to twice the minimum, whole kilos');
    },

    'purchase paid in cash from the till leaves the drawer' => function () use ($setup): void {
        ['meat' => $meat, 'sup' => $sup] = $setup();
        Shifts::open(['TRY' => 100000]);
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '2', 'unit_price' => '400']], ['supplier_id' => $sup, 'pay_method' => 'cash', 'doc_no' => 'F-12']);
        same(100000 - 88000, (int) Shifts::summary(Shifts::currentId())['cash']['TRY'], '₺800 + 10% VAT');
    },
];
