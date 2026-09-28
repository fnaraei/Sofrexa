<?php
/** Reports and finance: periods on the business day, sales figures, the income / expense list without double counting, P&L, the accountant package. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db, I18n, Settings};
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Modules\Reports\{Accountant, Reports};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $uid = Seed::user('Patron', 'manager', '1212');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $item = Menu::saveItem(['names' => ['tr' => 'Izgara'], 'category_id' => $cat, 'price' => '1100', 'vat_rate' => '10', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    Shifts::open(['TRY' => 1_000_000]);
    return compact('uid', 'item', 'table');
};
$sell = static function (array $s, int $qty = 1): string {
    $o = Orders::forTable($s['table']);
    Orders::addItem($o, $s['item'], $qty);
    Orders::send($o);
    Orders::pay($o, [['method' => 'card', 'amount' => (int) Orders::get($o)['total']]], null, false);
    return $o;
};

return [
    'periods follow the business day (rollover 05:00)' => function (): void {
        Settings::set('day.rollover_hour', 5);
        Clock::freeze((int) strtotime('2026-09-28 02:30:00') * 1000);
        $p = Reports::period('today');
        same('2026-09-27', $p['first'], 'after midnight it is still the evening before');
        same('2026-09-27', $p['last']);
        Clock::freeze((int) strtotime('2026-09-28 10:00:00') * 1000);
        $lm = Reports::period('last_month');
        same(['2026-08-01', '2026-08-31'], [$lm['first'], $lm['last']]);
        same((int) strtotime('2026-08-01 05:00:00') * 1000, $lm['from']);
        $m = Reports::period('month');
        same((int) strtotime('2026-08-01 05:00:00') * 1000, $m['prev_from'], 'this month compares with the same days of the month before');
        $c = Reports::period('custom', '2026-09-01', '2026-09-10');
        same(['2026-09-01', '2026-09-10'], [$c['first'], $c['last']]);
    },

    'sales figures: turnover, bills, VAT inside, voids after sending' => function () use ($setup, $sell): void {
        $s = $setup();
        $sell($s, 2);
        $sell($s, 1);
        $o = Orders::forTable($s['table']);
        $l = Orders::addItem($o, $s['item'], 1);
        Orders::send($o);
        Orders::voidLine($l, 'müşteri vazgeçti', 1);
        $p = Reports::period('today');
        $k = Reports::kpis($p['from'], $p['to']);
        same(330000, $k['sales']);
        same(2, $k['bills']);
        same(165000, $k['avg']);
        same(30000, $k['vat'], '10% inside ₺3.300');
        same(1, $k['voids_n']);
        same(110000, $k['voids']);
        same(1, count(Reports::voids($p['from'], $p['to'])));
        same(330000, Reports::channels($p['from'], $p['to'])['table']);
        same(330000, Reports::payments($p['from'], $p['to'])['card']);
    },

    'income and expenses: every source once, reversals out, recurring once a month' => function () use ($setup): void {
        $s = $setup();
        $sup = Stock::saveSupplier(['name' => 'Et Dünyası']);
        $meat = Stock::saveItem(['name' => 'Kıyma', 'unit' => 'kg', 'category' => 'Et']);
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '2', 'unit_price' => '400']], ['supplier_id' => $sup, 'pay_method' => 'cash', 'doc_no' => 'F-1']);
        Db::save('users', ['id' => $s['uid'], 'base_salary' => 1_000_000]);
        Staff::pay(date('Y-m'), [$s['uid'] => 300_000], 'cash');
        Shifts::move('out', 'TRY', 50_000, I18n::t('moves.r_market', [], 'fa'), 'نان');
        Shifts::move('out', 'TRY', 20_000, I18n::t('moves.r_coins', [], 'tr'));
        $energy = Finance::add(['kind' => 'expense', 'category' => 'energy', 'amount' => '1.820', 'method' => 'cash', 'description' => 'KIB-TEK']);
        $wrong = Finance::add(['kind' => 'expense', 'category' => 'marketing', 'amount' => '500', 'method' => 'bank']);
        Finance::reverse($wrong);
        Finance::add(['kind' => 'income', 'category' => 'event', 'amount' => '1.200', 'method' => 'bank']);
        $p = Reports::period('month');
        $rows = Finance::entries($p['from'], $p['to']);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['source'] . ':' . $r['category']] = ($by[$r['source'] . ':' . $r['category']] ?? 0) + $r['amount'];
        }
        ksort($by);
        same(['manual:energy' => 182000, 'manual:event' => 120000, 'payroll:staff' => 300000, 'stock:supplies' => 88000, 'till:supplies' => 50000], $by,
            'purchase and salary once (their cash moves not again), coins not an expense, the reversed entry gone');
        same(1_000_000 - 88000 - 300000 - 50000 - 20000 - 182000, (int) Shifts::summary(Shifts::currentId())['cash']['TRY'], 'the energy bill left the drawer');
        Finance::add(['kind' => 'expense', 'category' => 'rent', 'amount' => '60.000', 'method' => 'bank', 'recurring' => '1', 'day' => date('Y-m') . '-01']);
        same(0, Finance::runRecurring(), 'this month already booked');
        same(1, Finance::runRecurring(date('Y-m-d', (int) strtotime(date('Y-m-01') . ' +1 month'))));
        same(0, Finance::runRecurring(date('Y-m-d', (int) strtotime(date('Y-m-01') . ' +1 month'))), 'once a month');
    },

    'profit and loss: net sales, recipe cost, waste, operating expenses' => function () use ($setup, $sell): void {
        $s = $setup();
        $meat = Stock::saveItem(['name' => 'Kıyma', 'unit' => 'kg']);
        Stock::document('purchase', [['stock_item_id' => $meat, 'qty' => '10', 'unit_price' => '400']]);
        Stock::setRecipe('item', $s['item'], [['stock_item_id' => $meat, 'qty' => '0,25']]);
        $sell($s, 2);
        Stock::document('waste', [['stock_item_id' => $meat, 'qty' => '0,5', 'reason' => 'Bozuldu']]);
        Finance::add(['kind' => 'expense', 'category' => 'rent', 'amount' => '300', 'method' => 'bank']);
        $p = Reports::period('month');
        $pl = Finance::pl($p['from'], $p['to']);
        same(220000, $pl['gross_sales']);
        same(200000, $pl['net_sales']);
        same(20000, $pl['cogs'], '2 × 0,25 kg × ₺400');
        same(20000, $pl['waste']);
        same(160000, $pl['gross']);
        same(30000, $pl['opex']['rent']);
        same(130000, $pl['net']);
        same(440000, $pl['purchases'], 'the invoice (10% VAT included) is shown, not counted as an expense');
    },

    'the accountant package: xlsx, csv and pdf in a zip' => function () use ($setup, $sell): void {
        $s = $setup();
        $sell($s, 1);
        $p = Reports::period('month');
        $file = Accountant::zip($p, Accountant::PARTS, ['xlsx', 'csv', 'pdf']);
        $zip = new ZipArchive();
        same(true, $zip->open($file));
        $names = [];
        $base = 'muhasebe-' . $p['first'] . '_' . $p['last'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = substr($zip->getNameIndex($i), strlen($base));
        }
        sort($names);
        same(['-accounts.csv', '-expenses.csv', '-payments.csv', '-payroll.csv', '-purchases.csv', '-sales.csv', '-stock.csv', '.pdf', '.xlsx'], $names);
        $pdf = (string) $zip->getFromName('muhasebe-' . $p['first'] . '_' . $p['last'] . '.pdf');
        check(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF'), 'a PDF');
        $zip->close();
        @unlink($file);
    },
];
