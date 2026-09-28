<?php
/** Staff: clock in / out, commission bases and payroll with advances, permission switches on top of the role. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Setup\Seed;

$as = static function (string $uid): void {
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $waiter = Seed::user('Ayşe Yıldız', 'waiter', '2222');
    $cashier = Seed::user('Can Demir', 'cashier', '3333');
    $chef = Seed::user('Hakan Şahin', 'chef', '4444');
    $as($boss);
    $kitchen = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $bar = Db::save('categories', ['names' => ['tr' => 'İçecekler'], 'station' => 'bar']);
    $grill = Menu::saveItem(['names' => ['tr' => 'Izgara'], 'category_id' => $kitchen, 'price' => '1000', 'available' => 1]);
    $tea = Menu::saveItem(['names' => ['tr' => 'Çay'], 'category_id' => $bar, 'price' => '100', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]));
    Shifts::open(['TRY' => 1_000_000]);
    return compact('boss', 'waiter', 'cashier', 'chef', 'grill', 'tea', 'table');
};

return [
    'clock in and out: one open shift, history and worked time' => function () use ($setup): void {
        ['waiter' => $w] = $setup();
        Clock::freeze(1_790_000_000_000);
        $in = Staff::clockIn($w);
        same($in, Staff::clockIn($w), 'a second clock-in keeps the open one');
        same([$w => $in], array_map('intval', Staff::onShift()));
        Clock::freeze(1_790_000_000_000 + (8 * 60 + 10) * 60_000);
        same((8 * 60 + 10) * 60_000, Staff::clockOut($w));
        same([], Staff::onShift());
        same('8 sa 10 dk', Staff::duration(Staff::history($w)[0]['ms'], 'tr'));
        same((8 * 60 + 10) * 60_000, Staff::worked($w, 0, PHP_INT_MAX));
        try {
            Staff::clockOut($w);
            throw new LogicException('clocked out twice');
        } catch (\InvalidArgumentException) {
        }
    },

    'commission bases and the payroll with an advance paid from the till' => function () use ($setup, $as): void {
        $s = $setup();
        Db::save('users', ['id' => $s['waiter'], 'base_salary' => 1_800_000, 'commission_pct' => 3, 'pay_basis' => 'own']);
        Db::save('users', ['id' => $s['cashier'], 'base_salary' => 2_400_000, 'commission_pct' => 1, 'pay_basis' => 'till']);
        Db::save('users', ['id' => $s['chef'], 'base_salary' => 3_200_000, 'commission_pct' => 1, 'pay_basis' => 'kitchen']);
        // the waiter serves a bill (2 × grill, 5 × tea = ₺2.500), the cashier takes the money
        $as($s['waiter']);
        $o = Orders::forTable($s['table']);
        Orders::addItem($o, $s['grill'], 2);
        Orders::addItem($o, $s['tea'], 5);
        Orders::send($o);
        $as($s['cashier']);
        Orders::pay($o, [['method' => 'cash', 'amount' => 250000]], null, false);
        $as($s['boss']);
        [$from, $to] = Staff::period(date('Y-m'));
        same(250000, Staff::sales(Staff::user($s['waiter']), $from, $to), 'own bills');
        same(250000, Staff::sales(Staff::user($s['cashier']), $from, $to), 'payments taken');
        same(200000, Staff::sales(Staff::user($s['chef']), $from, $to), 'kitchen lines only');
        $rows = array_column(Staff::payroll(date('Y-m')), null, 'id');
        same(1_800_000 + 7_500, $rows[$s['waiter']]['earned'], '₺18.000 + 3% of ₺2.500');
        same(3_200_000 + 2_000, $rows[$s['chef']]['earned']);
        same(false, isset($rows[$s['boss']]), 'no pay model, not listed');
        Staff::pay(date('Y-m'), [$s['waiter'] => 500_000], 'cash', 'avans');
        $rows = array_column(Staff::payroll(date('Y-m')), null, 'id');
        same(500_000, $rows[$s['waiter']]['paid']);
        same(1_307_500, $rows[$s['waiter']]['left']);
        same('advance', Db::value('SELECT kind FROM payroll'));
        same(1_000_000 + 250_000 - 500_000, (int) Shifts::summary(Shifts::currentId())['cash']['TRY'], 'the advance left the drawer');
        Staff::pay(date('Y-m'), [$s['chef'] => 100_000], 'bank');
        same(1_000_000 + 250_000 - 500_000, (int) Shifts::summary(Shifts::currentId())['cash']['TRY'], 'a transfer does not touch the drawer');
        $t = Staff::payrollTotals(Staff::payroll(date('Y-m')));
        same(600_000, $t['paid']);
        same(3.0, $t['waiter_rate']);
    },

    'permission switches: on top of the role, a manager untouched' => function () use ($setup, $as): void {
        $s = $setup();
        $roles = array_column(Db::rows('SELECT id, code FROM roles'), 'id', 'code');
        $sw = Staff::switches(Staff::user($s['waiter']));
        same([true, true, true, false, false, false, true], array_values($sw), 'waiter defaults as on ST4');
        Staff::saveProfile($s['waiter'], ['role_id' => $roles['waiter'], 'commission_pct' => '%3', 'base_salary' => '₺18.000',
            'perms' => ['_' => 1, 'orders.take' => 1, 'orders.qr_approve' => 1, 'bill.print' => 1, 'orders.discount' => 1]]);
        $u = Staff::user($s['waiter']);
        same(3.0, (float) $u['commission_pct']);
        same(1_800_000, (int) $u['base_salary']);
        $as($s['waiter']);
        check(Auth::can('orders.discount'), 'discount given');
        check(!Auth::can('orders.transfer'), 'transfer taken away');
        check(Auth::can('orders.take'), 'role permission kept');
        $as($s['boss']);
        Staff::saveProfile($s['boss'], ['role_id' => $roles['manager'], 'perms' => ['_' => 1]]);
        same('[]', Staff::user($s['boss'])['perms_deny'], 'a manager keeps everything');
    },
];
