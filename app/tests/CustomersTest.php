<?php
/** Customers, accounts and loyalty: the form rules, tiers, the discount on a bill, points in and out, collections, the program settings. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db, Settings, ValidationError};
use Sofrexa\Modules\Customers\{Customers, Loyalty};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Accounts, Orders, Shifts};
use Sofrexa\Setup\Seed;

$setup = static function (): array {
    Seed::base(static fn() => null);
    $uid = Seed::user('Kasa Can', 'manager', '4747');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
    $cat = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $item = Menu::saveItem(['names' => ['tr' => 'Karışık Izgara'], 'category_id' => $cat, 'price' => '1000', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    $tiers = array_column(Loyalty::tiers(), 'id', 'name');
    Shifts::open(['TRY' => 0]);
    return compact('item', 'table', 'tiers');
};
$bill = static function (array $s, int $qty = 2): string {
    $o = Orders::forTable($s['table']);
    Orders::addItem($o, $s['item'], $qty);
    Orders::send($o);
    return $o;
};
$payAll = static function (string $o, string $method = 'cash', ?string $cid = null): void {
    $due = (int) Orders::get($o)['total'] - (int) Orders::get($o)['paid'];
    Orders::pay($o, [['method' => $method, 'amount' => $due]], $cid, false);
};

return [
    'the form: name and phone required, one customer per phone; list tags and head figures' => function () use ($setup): void {
        $setup();
        try {
            Customers::save(['name' => '', 'phone' => '12']);
            throw new LogicException('accepted an empty form');
        } catch (ValidationError $e) {
            check(isset($e->errors['name'], $e->errors['phone']), 'both fields flagged');
        }
        $a = Customers::save(['name' => 'Elena Petrova', 'phone' => '0533 842 17 90', 'loyalty' => 1, 'address' => 'Karaoğlanoğlu, Girne']);
        try {
            Customers::save(['name' => 'Başka', 'phone' => '0533 842 1790']);
            throw new LogicException('accepted a taken phone');
        } catch (ValidationError $e) {
            check(str_contains($e->errors['phone'], 'Elena'), 'names the owner');
        }
        $b = Customers::save(['name' => 'Oğuz Kaya', 'phone' => '0542 118 20 04', 'credit' => 1, 'company' => 'Kaya İnşaat']);
        same('Karaoğlanoğlu, Girne', Customers::addresses($a)[0]['address']);
        same('Bronz', Loyalty::tierOf($a)['name'], 'new customers start at the lowest tier');
        $rows = array_column(Customers::list(), null, 'id');
        same('credit', $rows[$b]['tag']);
        same('', $rows[$a]['tag']);
        same(1, Customers::summary(array_values($rows))['credit']);
        same([$b], array_column(Customers::list(['q' => 'kaya']), 'id'), 'search by company');
    },

    'tier: automatic from spending, a hand-set tier stays, back to automatic' => function () use ($setup, $bill, $payAll): void {
        $s = $setup();
        $c = Customers::save(['name' => 'Ahmet Yılmaz', 'phone' => '0533 412 77 90', 'loyalty' => 1]);
        $payAll($bill($s, 10), 'cash', $c);
        same('Gümüş', Loyalty::tierOf($c)['name'], '₺10.000 in the window');
        Loyalty::setTier($c, $s['tiers']['Altın'], false, 'Kurumsal');
        Loyalty::refreshAll();
        same('Altın', Loyalty::tierOf($c)['name'], 'manual tier kept by the nightly refresh');
        Loyalty::setTier($c, null, true);
        same('Gümüş', Loyalty::tierOf($c)['name']);
    },

    'a customer on the bill: the bigger of own and tier discount; points on what was paid' => function () use ($setup, $bill, $payAll): void {
        $s = $setup();
        $c = Customers::save(['name' => 'Murat Demir', 'phone' => '0548 220 31 09', 'loyalty' => 1, 'discount_pct' => '5']);
        Loyalty::setTier($c, $s['tiers']['Gümüş'], false);
        $o = $bill($s);
        Loyalty::attach($o, $c);
        same(190000, (int) Orders::get($o)['total'], 'own 5% beats the tier 3%');
        Db::save('customers', ['id' => $c, 'discount_pct' => 0]);
        Loyalty::attach($o, null);
        same(200000, (int) Orders::get($o)['total'], 'discount goes with the customer');
        Loyalty::attach($o, $c);
        same(194000, (int) Orders::get($o)['total'], 'tier 3%');
        $payAll($o);
        same(135, Loyalty::balance($c), '7% of ₺1.940 = 135 points');
        // a discount given by hand: no points (rule on)
        $o2 = $bill($s, 1);
        Loyalty::attach($o2, $c);
        Orders::clearDiscount($o2);
        Orders::discount($o2, 'pct', 10, 'Müdavim');
        $payAll($o2);
        same(135, Loyalty::balance($c), 'no points on a bill discounted by hand');
    },

    'points: use, take back, a cancelled bill returns them, the minimum' => function () use ($setup, $bill): void {
        $s = $setup();
        $c = Customers::save(['name' => 'Selin Bulut', 'phone' => '0533 311 73 64', 'loyalty' => 1]);
        Loyalty::adjust($c, 500, 'ItKafe bonus');
        $o = $bill($s);
        Loyalty::attach($o, $c);
        same(50000, Loyalty::redeem($o, 800), 'capped by the balance');
        same(150000, (int) Orders::get($o)['total']);
        same(0, Loyalty::balance($c));
        Loyalty::unredeem($o);
        same(200000, (int) Orders::get($o)['total']);
        same(500, Loyalty::balance($c));
        Loyalty::redeem($o, 300);
        Orders::void($o, 'müşteri gitti');
        same(500, Loyalty::balance($c), 'voided bill gives the points back');
        $d = Customers::save(['name' => 'Deniz Aksoy', 'phone' => '0542 999 10 20', 'loyalty' => 1]);
        Loyalty::adjust($d, 50, 'hoş geldin');
        $o2 = $bill($s);
        Loyalty::attach($o2, $d);
        try {
            Loyalty::redeem($o2, 50);
            throw new LogicException('used under the minimum');
        } catch (ValidationError) {
        }
    },

    'account: a bill on account, collections in and outside the shift, the statement' => function () use ($setup, $bill, $payAll): void {
        $s = $setup();
        $c = Customers::save(['name' => 'Oğuz Kaya', 'phone' => '0542 118 20 04', 'credit' => 1, 'loyalty' => 1]);
        Db::save('customers', ['id' => $c, 'credit_limit' => 300000]);
        $o = $bill($s);
        $payAll($o, 'account', $c);
        same(200000, Accounts::balance($c));
        same(0, Loyalty::balance($c), 'no points on account');
        try {
            $payAll($bill($s), 'account', $c);
            throw new LogicException('over the limit');
        } catch (\InvalidArgumentException) {
        }
        same(150000, Accounts::settle($c, 50000, 'cash'));
        same(50000, (int) Shifts::summary(Shifts::currentId())['cash']['TRY'], 'cash collection is in the drawer');
        same(100000, Accounts::settle($c, 50000, 'transfer'));
        same(null, Db::value("SELECT shift_id FROM payments WHERE method = 'transfer'"), 'a bank transfer is not a till event');
        $l = Customers::ledger($c, date('Y-m'));
        same(3, count($l['rows']));
        same(100000, $l['closing']);
        same(200000, (int) end($l['rows'])['balance'], 'running balance, oldest last');
        try {
            Customers::delete($c);
            throw new LogicException('removed with a balance');
        } catch (\InvalidArgumentException) {
        }
    },

    'program: point value and tiers from the admin form; old unused points expire' => function () use ($setup): void {
        $s = $setup();
        Loyalty::saveProgram([
            'point_value' => '₺2', 'tier_window' => '6', 'min_redeem' => '50', 'expiry' => '12',
            'no_points_on_discounted' => '1', 'points_on_delivery' => '1',
            'tiers' => [$s['tiers']['Gümüş'] => ['threshold' => '₺8.000 +', 'discount_pct' => '%4', 'earn_pct' => '%8']],
            'new' => [['name' => 'Platin', 'tone' => 'Solid', 'threshold' => '50000', 'discount_pct' => '10', 'earn_pct' => '12,5']],
        ]);
        Settings::flush();
        same(200, Loyalty::pointValue());
        same(6, (int) Settings::get('loyalty.tier_window_months'));
        same(false, Settings::get('loyalty.no_points_on_account'), 'unchecked switch saved off');
        $g = Loyalty::tier($s['tiers']['Gümüş']);
        same([800000, 4.0, 8.0], [(int) $g['threshold'], (float) $g['discount_pct'], (float) $g['earn_pct']]);
        same(['Bronz', 'Gümüş', 'Altın', 'Platin'], array_column(Loyalty::tiers(), 'name'));
        same(12.5, (float) Loyalty::tiers()[3]['earn_pct']);
        $c = Customers::save(['name' => 'Eski Müşteri', 'phone' => '0533 000 00 01', 'loyalty' => 1]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 300, 'kind' => 'earn', 'at' => Clock::ms() - 400 * 86_400_000]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => -100, 'kind' => 'redeem', 'at' => Clock::ms() - 10 * 86_400_000]);
        Db::append('loyalty_ledger', ['customer_id' => $c, 'points' => 40, 'kind' => 'earn', 'at' => Clock::ms() - 5 * 86_400_000]);
        same(1, Loyalty::expire());
        same(40, Loyalty::balance($c), 'the 200 old unused points expire, the recent 40 stay');
        same(0, Loyalty::expire(), 'nothing twice');
    },
];
