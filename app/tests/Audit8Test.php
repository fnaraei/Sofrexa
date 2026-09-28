<?php
/**
 * The eighth audit (2026-09-29): 21 findings, and the 4 of the seventh still open. They were fixed as classes (decisions
 * 51–56), and each class is tested here from what it must guarantee, not only from the one case the audit showed:
 *   - nobody hands out more than they hold; a new PIN or password ends the old sessions and links (S01–S03);
 *   - a CSV cell is never a formula (S04);
 *   - cash moves only where the till is, in an open shift, and a closed shift is never written to again (S05, O04, O05);
 *   - whatever is checked before a write is checked inside the write lock — raced here by two real processes (O06–O10);
 *   - a typed number is read one way, and anything else is refused, never taken as 0 (O02, O03);
 *   - a month already worked keeps its pay terms (O11); "Fire %" costs and takes stock (O01);
 *   - a backup is its own file and a restore puts the uploads back exactly (O12, O13);
 *   - an approval is all or nothing (O14); an opening past midnight is one opening (O15); a printer prints in order (O16);
 *   - paying and handing over a bag are two ends (E01–E04).
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, HttpError, Perms, Settings, ValidationError};
use Sofrexa\Export\Csv;
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\Modules\Orders\{Delivery, Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Modules\Staff\{RolesController, Staff, Users};
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Print\Spooler;
use Sofrexa\Setup\Seed;

$as = static function (string $uid): void {
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
};
$setup = static function (bool $shift = true) use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $pizza = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1, 'show_qr' => 1, 'show_online' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Salon']]), '5');
    if ($shift) {
        Shifts::open(['TRY' => 0]);
    }
    return ['boss' => $boss, 'pizza' => $pizza, 'table' => $table, 'cat' => $cat];
};
$fails = static function (callable $fn, string $class = \Throwable::class): string {
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            throw $e;
        }
        return $e->getMessage();
    }
    throw new RuntimeException('expected ' . $class . ' at line ' . (debug_backtrace()[0]['line'] ?? '?'));
};
/**
 * Two real PHP processes doing $op at the same moment: both are held behind this process's write lock, let go together,
 * and their answers returned — the way two tills (or the worker and a page) meet in a real restaurant.
 */
$race = static function (string $op, array $args): array {
    $dir = (string) App::config('storage');
    $key = bin2hex(random_bytes(3));
    $cfg = "$dir/race-$key.php";
    file_put_contents($cfg, '<?php return ' . var_export(['db' => App::config('db'), 'storage' => $dir, 'role' => 'pc', 'debug' => true,
        'sync' => ['remote_url' => '', 'key' => '']], true) . ';');
    $child = "$dir/race-child.php";
    file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);
putenv('SOFREXA_CONFIG=' . $argv[1]);
require $argv[2] . '/bootstrap.php';
$a = json_decode($argv[3], true);
\Sofrexa\Core\Auth::actAs(\Sofrexa\Core\Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$a['user']]));
\Sofrexa\Core\Clock::freeze($a['now']);
file_put_contents($argv[4], 'ready');
try {
    $r = match ($a['op']) {
        'points' => \Sofrexa\Modules\Customers\Loyalty::redeem($a['orders'][(int) $argv[5]], 100),
        'reverse' => \Sofrexa\Modules\Orders\Shifts::reverse($a['move']),
        'shift' => \Sofrexa\Modules\Orders\Shifts::open(['TRY' => 0]),
        'recurring' => \Sofrexa\Modules\Finance\Finance::runRecurring($a['today']),
        'clockin' => \Sofrexa\Modules\Staff\Staff::clockIn($a['user']),
    };
    echo json_encode(['ok' => true, 'r' => $r]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
PHP);
    $args += ['op' => $op, 'user' => Auth::user()['id'], 'now' => Clock::ms()];
    $pdo = Db::pdo();
    $pdo->exec('BEGIN IMMEDIATE'); // both start, read what they read, and wait here for the lock
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        @unlink("$dir/race-$key-$i");
        $procs[] = proc_open([PHP_BINARY, $child, $cfg, APP_DIR, json_encode($args), "$dir/race-$key-$i", (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    for ($t = 0; $t < 300 && !(is_file("$dir/race-$key-0") && is_file("$dir/race-$key-1")); $t++) {
        usleep(20_000);
    }
    usleep(400_000);
    $pdo->exec('COMMIT');
    $out = [];
    foreach ($procs as $i => $p) {
        $out[] = json_decode((string) stream_get_contents($pipes[$i][1]), true) ?? ['ok' => false, 'error' => stream_get_contents($pipes[$i][2])];
        proc_close($p);
        @unlink("$dir/race-$key-$i");
    }
    @unlink($cfg);
    return $out;
};

return [
    // ------------------------------------------------------------ S01–S03
    'S01 a delegated staff manager hands out only what they hold, and never touches an account above theirs' => function () use ($setup, $as, $fails): void {
        $s = $setup();
        $floor = Db::save('roles', ['code' => 'rfloor', 'name' => 'Şef garson', 'perms' => ['orders.take', 'orders.qr_approve', 'orders.void', 'staff.manage']]);
        $chief = Seed::user('Şef', 'waiter', '2345');
        Db::save('users', ['id' => $chief, 'role_id' => $floor]);
        $waiter = Seed::user('Garson', 'waiter', '3456');
        $cashierRole = Db::value("SELECT id FROM roles WHERE code = 'cashier'");
        $managerRole = Db::value("SELECT id FROM roles WHERE code = 'manager'");
        $waiterRole = Db::value("SELECT id FROM roles WHERE code = 'waiter'");
        $as($chief);
        // the page sends the whole matrix: every role as it is, with the change
        $matrix = static fn(array $change): array => $change + array_map(static fn(string $p): array => json_arr($p), Db::pairs('SELECT id, perms FROM roles WHERE deleted = 0'));
        // what they hold they may give
        RolesController::saveMatrix($matrix([$waiterRole => ['orders.take', 'orders.qr_approve', 'orders.void']]));
        check(in_array('orders.void', json_arr(Db::value('SELECT perms FROM roles WHERE id = ?', [$waiterRole])), true), 'a switch they hold');
        // "*" or a permission they lack: refused, and nothing saved
        $fails(static fn() => RolesController::saveMatrix($matrix([$floor => ['*']])), HttpError::class);
        $fails(static fn() => RolesController::saveMatrix($matrix([$floor => ['orders.take', 'orders.qr_approve', 'orders.void', 'staff.manage', 'cash.pay']])), HttpError::class);
        $fails(static fn() => RolesController::saveMatrix($matrix([$cashierRole => ['orders.take']])), HttpError::class); // taking away what they lack
        same(['orders.take', 'orders.qr_approve', 'orders.void', 'staff.manage'], json_arr(Db::value('SELECT perms FROM roles WHERE id = ?', [$floor])));
        // a role above theirs, to anyone, themselves included
        $fails(static fn() => Users::save(['id' => $chief, 'name' => 'Şef', 'role_id' => $managerRole]), HttpError::class);
        $fails(static fn() => Users::save(['id' => $waiter, 'name' => 'Garson', 'role_id' => $cashierRole]), HttpError::class);
        $fails(static fn() => Staff::saveProfile($waiter, ['role_id' => $managerRole]), HttpError::class);
        $fails(static fn() => Staff::saveProfile($waiter, ['role_id' => $waiterRole, 'perms' => ['cash.pay' => 1, 'orders.take' => 1, 'orders.qr_approve' => 1]]), HttpError::class);
        // the manager's account: not its e-mail (its password link would follow), not its PIN, not its switch-off
        $fails(static fn() => Users::save(['id' => $s['boss'], 'name' => 'Patron', 'role_id' => $managerRole, 'email' => 'x@example.com']), HttpError::class);
        $fails(static fn() => Users::resetPin($s['boss']), HttpError::class);
        $fails(static fn() => Users::deactivate($s['boss']), HttpError::class);
        same(null, Db::value('SELECT email FROM users WHERE id = ?', [$s['boss']]));
        // a waiter's account is theirs to manage
        Users::save(['id' => $waiter, 'name' => 'Garson Ali', 'role_id' => $waiterRole]);
        check(strlen(Users::resetPin($waiter)) === 4, 'a new PIN');
        // the manager still does everything
        $as($s['boss']);
        Users::save(['id' => $chief, 'name' => 'Şef', 'role_id' => $cashierRole]);
        RolesController::saveMatrix($matrix([$floor => ['orders.take', 'cash.pay']]));
        same($cashierRole, Db::value('SELECT role_id FROM users WHERE id = ?', [$chief]));
    },

    'S02 S03 a new password ends every other link and session; a new PIN ends the sessions opened with the old one' => function () use ($setup): void {
        $setup();
        $u = Seed::user('Ayşe', 'cashier', '2345');
        Db::save('users', ['id' => $u, 'email' => 'ayse@example.com']);
        $link = static function (string $uid): string {
            $t = bin2hex(random_bytes(8));
            Db::save('password_resets', ['user_id' => $uid, 'token_hash' => hash('sha256', $t), 'expires_at' => Clock::ms() + 3_600_000]);
            return $t;
        };
        [$old, $new] = [$link($u), $link($u)];
        Users::setPassword(Users::resetByToken($new), 'yeni-sifre-2026');
        same(null, Users::resetByToken($old), 'the older link sets nothing any more');
        same(null, Users::resetByToken($new), 'nor the one used');
        // one link used twice at once sets one password
        $t = $link($u);
        $reset = Users::resetByToken($t);
        Users::setPassword($reset, 'ikinci-sifre-2026');
        try {
            Users::setPassword($reset, 'ucuncu-sifre-2026');
            check(false, 'the second use is refused');
        } catch (\InvalidArgumentException) {
        }
        check(password_verify('ikinci-sifre-2026', (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$u])), 'the first use counts');
        // a changed e-mail: the links sent to the old address end
        $t = $link($u);
        Users::save(['id' => $u, 'name' => 'Ayşe', 'role_id' => Db::value('SELECT role_id FROM users WHERE id = ?', [$u]), 'email' => 'ayse2@example.com']);
        same(null, Users::resetByToken($t));

        // sessions: the credentials the session was opened with
        $cv = new ReflectionMethod(Auth::class, 'credentials');
        $loaded = new ReflectionProperty(Auth::class, 'loaded');
        $session = static function () use ($loaded): ?array {
            Auth::actAs(null);
            $loaded->setValue(null, false);
            return Auth::user();
        };
        $_SESSION = ['uid' => $u, 'cv' => $cv->invoke(null, Db::row('SELECT * FROM users WHERE id = ?', [$u]))];
        same($u, $session()['id'] ?? null, 'signed in');
        Auth::actAs(Db::row("SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'manager'"));
        Users::resetPin($u);
        same(null, $session(), 'the new PIN signs the old session out');
        $_SESSION = [];
    },

    'S04 a name that looks like a formula is text in every CSV' => function (): void {
        same("'=HYPERLINK(\"x\")", Csv::cell('=HYPERLINK("x")'));
        same("'+90 555", Csv::cell('+90 555'), 'a phone with a plus is text too');
        same(['-12,50', '1.234,50', '%10', 'Ayşe', 'علی', ''], array_map([Csv::class, 'cell'], ['-12,50', '1.234,50', '%10', 'Ayşe', 'علی', '']), 'numbers and names as they are');
        $csv = Staff::csv('2026-09', [['name' => '@SUM(A1)', 'role_code' => 'waiter', 'sales' => 0, 'commission_pct' => 0, 'base_salary' => 0, 'deliveries' => 0, 'earned' => -500, 'paid' => 0, 'left' => -500]]);
        check(str_contains($csv, "'@SUM(A1)") && str_contains($csv, '-5,00'), 'the payroll export');
    },

    // ------------------------------------------------------------ S05, O04, O05
    'S05 O04 cash is booked only where the till is and in an open shift — nothing else is booked either' => function () use ($setup, $fails): void {
        $s = $setup(false);
        $stock = Db::save('stock_items', ['name' => 'Un', 'unit' => 'kg', 'avg_cost' => 1000]);
        $count = static fn(): array => [(int) Db::value('SELECT COUNT(*) FROM stock_docs'), (int) Db::value('SELECT COUNT(*) FROM finance_entries'), (int) Db::value('SELECT COUNT(*) FROM payroll'), (int) Db::value('SELECT COUNT(*) FROM cash_moves')];
        // no shift open
        $fails(static fn() => Stock::document('purchase', [['stock_item_id' => $stock, 'qty' => '2', 'unit_price' => '11']], ['pay_method' => 'cash']));
        $fails(static fn() => Finance::add(['kind' => 'income', 'category' => 'other_in', 'amount' => '100', 'method' => 'cash']), ValidationError::class);
        $fails(static fn() => Staff::pay('2026-09', [$s['boss'] => 10000], 'cash'));
        same([0, 0, 0, 0], $count(), 'no invoice, entry or salary booked as paid in cash');
        same(0.0, Stock::onHand($stock), 'and no stock came in');
        Finance::add(['kind' => 'income', 'category' => 'other_in', 'amount' => '100', 'method' => 'bank']);
        // the web copy that is not standing in for the PC: never the drawer, whatever the page
        Shifts::open(['TRY' => 0]);
        App::setConfig('role', 'web');
        App::setConfig('sync.key', 'k');
        try {
            $fails(static fn() => Staff::pay('2026-09', [$s['boss'] => 10000], 'cash'), HttpError::class);
            $fails(static fn() => Finance::add(['kind' => 'expense', 'category' => 'other', 'amount' => '100', 'method' => 'cash']), HttpError::class);
            $fails(static fn() => Shifts::move('in', 'TRY', 1000, 'test'), HttpError::class);
            $fails(static fn() => \Sofrexa\Modules\Orders\Accounts::settle(Db::save('customers', ['name' => 'Cari']), 1000, 'cash'), HttpError::class);
            Staff::pay('2026-09', [$s['boss'] => 10000], 'bank'); // a bank transfer is not the drawer's
        } finally {
            App::setConfig('role', 'pc');
            App::setConfig('sync.key', '');
        }
        same(0, (int) Db::value('SELECT COUNT(*) FROM cash_moves'), 'the drawer untouched');
        same(0, (int) Db::value("SELECT COUNT(*) FROM payments WHERE method = 'cash'"), 'no cash taken on account either');
        // with the till and a shift: the invoice, the stock and the cash go together
        Stock::document('purchase', [['stock_item_id' => $stock, 'qty' => '2', 'unit_price' => '11']], ['pay_method' => 'cash']);
        same(-2420, (int) Db::value("SELECT amount FROM cash_moves WHERE ref LIKE 'stock_doc:%'"), '₺22 and its VAT');
    },

    'O05 a closed shift is never written again: its mistake is put right in the shift open now, or not at all' => function () use ($setup, $fails): void {
        $setup();
        $first = Shifts::currentId();
        $move = Shifts::move('out', 'TRY', 1000, 'Temizlik malzemesi');
        $entry = Finance::add(['kind' => 'income', 'category' => 'other_in', 'amount' => '50', 'method' => 'cash']);
        $before = Shifts::summary($first)['cash'];
        Shifts::close(['TRY' => (int) $before['TRY']]);
        // no shift: neither ledger moves
        $fails(static fn() => Shifts::reverse($move));
        $fails(static fn() => Finance::reverse($entry));
        same(0, (int) Db::value('SELECT COUNT(*) FROM finance_entries WHERE reverses IS NOT NULL'), 'the entry stays, as the drawer does');
        // the next shift takes the corrections
        $next = Shifts::open(['TRY' => 0]);
        $r = Shifts::reverse($move);
        Finance::reverse($entry);
        same($next, Db::value('SELECT shift_id FROM cash_moves WHERE id = ?', [$r]));
        same($before, Shifts::summary($first)['cash'], 'the closed shift adds up as it did');
        same(1000 - 5000, (int) Shifts::summary($next)['cash']['TRY'], '₺10 back in, ₺50 back out');
        check(str_contains((string) Db::value('SELECT reason FROM cash_moves WHERE id = ?', [$r]), 'Z '), 'naming the Z it corrects');
    },

    // ------------------------------------------------------------ O06–O10: two processes at once
    'O06–O10 two at once: points spent once, a move reversed once, one shift, a month booked once, one clock-in' => function () use ($setup, $race): void {
        $s = $setup();
        $c = Db::save('customers', ['name' => 'Yarış']);
        Loyalty::adjust($c, 100, 'test');
        $orders = [];
        foreach ([0, 1] as $i) {
            $orders[] = $o = Orders::create('table', ['table_id' => $s['table'], 'customer_id' => $c]);
            Orders::addItem($o, $s['pizza']);
        }
        $r = $race('points', ['orders' => $orders]);
        same(1, count(array_filter($r, static fn(array $x): bool => $x['ok'])), 'one of the two spent them');
        same(0, Loyalty::balance($c), 'never below nothing');

        $move = Shifts::move('in', 'TRY', 1000, 'test');
        $race('reverse', ['move' => $move]);
        same(1, (int) Db::value('SELECT COUNT(*) FROM cash_moves WHERE reverses = ?', [$move]));

        Shifts::close(['TRY' => (int) Shifts::summary(Shifts::currentId())['cash']['TRY']]);
        $race('shift', []);
        same(1, (int) Db::value('SELECT COUNT(*) FROM shifts WHERE closed_at IS NULL AND deleted = 0'));

        Db::save('recurring_expenses', ['category' => 'rent', 'amount' => 10000, 'day_of_month' => 1, 'method' => 'bank', 'active' => 1, 'last_run' => '2026-08']);
        $race('recurring', ['today' => '2026-09-28']);
        same(1, (int) Db::value("SELECT COUNT(*) FROM finance_entries WHERE source = 'recurring'"));

        $race('clockin', []);
        same(1, (int) Db::value("SELECT COUNT(*) FROM time_entries WHERE kind = 'in'"));
    },

    // ------------------------------------------------------------ O02, O03
    'O02 O03 numbers are read one way — Persian digits, "1.234,56" — and nonsense is refused, not taken as 0' => function () use ($setup, $fails): void {
        $setup();
        same([10.0, 1234.56, 36.82, 1234.0, 1.5, 0.0], array_map(static fn(string $v): float => read_num($v), ['۱۰', '1.234,56', '36.82', '1.234', '۱٫۵', '']));
        $un = Db::save('stock_items', ['name' => 'Un', 'unit' => 'kg', 'avg_cost' => 1000]);
        Db::append('stock_moves', ['stock_item_id' => $un, 'qty' => 7, 'unit_cost' => 1000, 'reason' => 'purchase', 'at' => Clock::ms()]);
        Stock::count([$un => '۱۰']);
        same(10.0, Stock::onHand($un), 'counted ten, in Persian digits');
        $fails(static fn() => Stock::count([$un => '1,2,3']), ValidationError::class);
        same(10.0, Stock::onHand($un), 'nonsense changed nothing');
        \Sofrexa\Modules\Orders\Rates::set('EUR', 40);
        $id = Finance::add(['kind' => 'income', 'category' => 'other_in', 'currency' => 'EUR', 'amount' => '1.234,56', 'method' => 'bank']);
        same([1234.56, 4938240], [(float) Finance::entry($id)['amount_fx'], (int) Finance::entry($id)['amount']]);
    },

    // ------------------------------------------------------------ O11
    'O11 a month already worked keeps its pay: changing the salary in September leaves August as it was' => function () use ($setup): void {
        $setup();
        $w = Seed::user('Garson', 'waiter', '2345');
        $role = Db::value('SELECT role_id FROM users WHERE id = ?', [$w]);
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '1.000', 'commission_pct' => '0']);
        same(1, (int) Db::value('SELECT COUNT(*) FROM pay_terms WHERE user_id = ?', [$w]), 'the database kept the terms');
        // as if the salary had been set in July
        Db::exec('UPDATE pay_terms SET from_month = ? WHERE user_id = ?', ['2026-07', $w]);
        $august = static fn(): int => (int) array_column(Staff::payroll('2026-08'), 'earned', 'id')[$w];
        same(100000, $august());
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '2.000', 'commission_pct' => '0']);
        Db::save('users', ['id' => $w, 'per_delivery' => 500]); // however the profile changes
        same(100000, $august(), 'August as it was');
        same(200000, (int) array_column(Staff::payroll(date('Y-m')), 'earned', 'id')[$w], 'this month on the new salary');
        same(3, (int) Db::value('SELECT COUNT(*) FROM pay_terms WHERE user_id = ?', [$w]));
    },

    // ------------------------------------------------------------ O01
    'O01 "Fire %" is what preparing loses: 50% fire costs and takes twice the plate’s quantity' => function () use ($setup): void {
        $s = $setup();
        $un = Db::save('stock_items', ['name' => 'Patates', 'unit' => 'kg', 'avg_cost' => 1000]);
        Db::append('stock_moves', ['stock_item_id' => $un, 'qty' => 10, 'unit_cost' => 1000, 'reason' => 'purchase', 'at' => Clock::ms()]);
        Stock::setRecipe('item', $s['pizza'], [['stock_item_id' => $un, 'qty' => '1', 'waste_pct' => '0']]);
        same(1000, Stock::itemCost($s['pizza'])['total']);
        Stock::setRecipe('item', $s['pizza'], [['stock_item_id' => $un, 'qty' => '1', 'waste_pct' => '50']]);
        same(2000, Stock::itemCost($s['pizza'])['total'], 'twice the cost');
        $o = Orders::create('table', ['table_id' => $s['table']]);
        Orders::addItem($o, $s['pizza']);
        Orders::send($o);
        same(8.0, Stock::onHand($un), 'and twice the stock');
        // through a semi-finished item too
        $puree = Db::save('stock_items', ['name' => 'Püre', 'unit' => 'kg', 'kind' => 'semi', 'avg_cost' => 0]);
        Stock::setRecipe('stock', $puree, [['stock_item_id' => $un, 'qty' => '1', 'waste_pct' => '20']]);
        same(1250, (int) round(Stock::unitCost($puree)));
    },

    // ------------------------------------------------------------ O12, O13
    'O12 O13 two backups in one second are two files; a restore puts the uploads back exactly' => function () use ($setup): void {
        $s = $setup();
        $up = App::storage('uploads');
        @mkdir($up, 0775, true);
        foreach (glob($up . '/*') ?: [] as $f) {
            @unlink($f);
        }
        file_put_contents($up . '/before.txt', 'before');
        Clock::freeze(1_790_593_200_000);
        $a = Backup::create('manual');
        $hash = hash_file('sha256', $a);
        Db::save('items', ['id' => $s['pizza'], 'price' => 99999]);
        $b = Backup::create('manual');
        check($a !== $b && hash_file('sha256', $a) === $hash, 'the first backup is untouched');
        file_put_contents($up . '/after.txt', 'after');
        unlink($up . '/before.txt');
        Backup::restore($a);
        same([true, false], [is_file($up . '/before.txt'), is_file($up . '/after.txt')]);
        same(10000, (int) Db::value('SELECT price FROM items WHERE id = ?', [$s['pizza']]));
        foreach (glob(Backup::dir() . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($up . '/before.txt');
    },

    // ------------------------------------------------------------ O14
    'O14 an approval is all or nothing: a dish run out since keeps the guest order waiting, to approve again later' => function () use ($setup, $as, $fails): void {
        $s = $setup();
        $table = Floor::table($s['table']);
        Auth::actAs(null);
        [$guest] = QrOrders::submit($table, [['item' => $s['pizza'], 'qty' => 1, 'mods' => [], 'note' => '']]);
        $as($s['boss']);
        Db::save('items', ['id' => $s['pizza'], 'daily_stock' => 0]); // run out since the guest ordered
        $fails(static fn() => QrOrders::accept($guest, $s['boss']));
        $o = Orders::get($guest);
        same(['pending', null, ['new']], [$o['status'], $o['approved_at'], array_column($o['lines'], 'status')], 'nothing of it happened');
        same(null, Db::value('SELECT approved_at FROM qr_sessions WHERE id = ?', [$o['qr_session_id']]), 'the phone is not trusted yet');
        Db::save('items', ['id' => $s['pizza'], 'daily_stock' => null]);
        QrOrders::accept($guest, $s['boss']);
        same('sent', Orders::get($guest)['lines'][0]['status'], 'approved the second time');
    },

    // ------------------------------------------------------------ O15
    'O15 an opening past midnight is one opening: at 00:30 the times to 01:45 are still there' => function () use ($setup): void {
        $setup();
        Settings::set('online.hours', '18:00 – 02:00');
        Clock::freeze((int) strtotime('2026-09-28 00:30:00') * 1000);
        same(['01:30', '01:45'], OnlineOrders::slots());
        Clock::freeze((int) strtotime('2026-09-28 17:00:00') * 1000);
        $slots = OnlineOrders::slots();
        same(['18:00', '01:45'], [$slots[0], end($slots)], 'before it opens: tonight, past midnight included');
        Settings::set('online.hours', '11:30 – 22:30');
        Clock::freeze((int) strtotime('2026-09-28 20:00:00') * 1000);
        $slots = OnlineOrders::slots();
        same(['21:00', '22:15'], [$slots[0], end($slots)], 'a day opening as before');
    },

    // ------------------------------------------------------------ O16
    'O16 a printer prints in the order tickets were made: a cancellation never comes out before its order' => function () use ($setup): void {
        $setup();
        Clock::freeze(1_790_593_200_000);
        Settings::set('printer.kitchen', ['driver' => 'tcp', 'target' => '']);
        Settings::set('printer.bar', ['driver' => 'file', 'target' => '']);
        Spooler::print('kitchen', 'kitchen', 'ORDER');
        Spooler::print('kitchen', 'void', 'CANCEL');
        Spooler::print('bar', 'bar', 'DRINKS');
        Spooler::work();
        Clock::freeze(Clock::ms() + 1000);
        Spooler::work();
        same('done', Db::value("SELECT status FROM print_jobs WHERE kind = 'bar'"), 'another printer goes on');
        Settings::set('printer.kitchen', ['driver' => 'file', 'target' => '']);
        Clock::freeze(Clock::ms() + 500); // the order still waits for its retry
        Spooler::work();
        same(['retry', 'queued'], array_column(Db::rows("SELECT status FROM print_jobs WHERE printer = 'kitchen' ORDER BY at, rowid"), 'status'), 'the cancellation waits behind it');
        Clock::freeze(Clock::ms() + 30_000);
        Spooler::work();
        same(['kitchen', 'void'], array_column(Db::rows("SELECT kind FROM print_jobs WHERE printer = 'kitchen' ORDER BY done_at, rowid"), 'kind'));
    },

    // ------------------------------------------------------------ E01–E04
    'E01–E04 paying and handing over are two ends: the fee on the bill, a paid bag on the board until it goes, the board and the kitchen agree' => function () use ($setup, $fails): void {
        $s = $setup();
        Settings::set('online.delivery_fee', 2500);
        $courier = Seed::user('Emre Kurye', 'courier', '6060');
        $new = static fn(string $type = 'delivery'): string => Delivery::create(['type' => $type, 'phone' => '0533 412 77 90', 'name' => 'Ahmet', 'address' => 'Levent 4',
            'courier_id' => $courier, 'pay' => 'cash', 'items' => [['item_id' => $s['pizza'], 'qty' => 1]]]);
        $onBoard = static fn(string $id): bool => in_array($id, array_column(Delivery::open(), 'id'), true);
        // E01: what the form showed
        $d = $new();
        same(12500, (int) Orders::get($d)['total'], 'the ₺25 fee is on the bill');
        same(10000, (int) Orders::get($new('pickup'))['total'], 'a pickup has none');
        // E04: the fee is no dish — the food ready is the order ready
        same('kitchen', Delivery::stage(Orders::get($d)));
        Kitchen::ready($d, 1, 'kitchen');
        same('ready', Delivery::stage(Orders::get($d)));
        // E02: paid before it leaves: still on the board, sent out, delivered — and the courier owes nothing for it
        Orders::pay($d, [['method' => 'card', 'amount' => 12500]], null, false);
        check($onBoard($d), 'a paid bag not yet delivered is on the board');
        Delivery::move($d, 'way');
        same('way', OnlineOrders::track(Orders::get($d))['stage'], 'the guest does not read "delivered" yet');
        Delivery::move($d, 'done');
        check(!$onBoard($d), 'delivered and paid: off the board');
        same(0, Delivery::settle($courier), 'nothing to hand in');
        same(12500, (int) Db::value('SELECT SUM(amount) FROM payments WHERE order_id = ?', [$d]), 'paid once');
        // E03: the board's "Hazır" is the kitchen's; a bag with a dish still cooking does not leave
        $e = $new();
        $fails(static fn() => Delivery::move($e, 'way'));
        Delivery::move($e, 'ready');
        same(['ready', 1], [Db::value("SELECT status FROM order_items WHERE order_id = ? AND sent_at IS NOT NULL", [$e]), (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$e])]);
        Delivery::move($e, 'way');
        Delivery::move($e, 'done');
        Kitchen::ready($e, 1, 'kitchen'); // a late tap in the kitchen
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'ready' AND ref_id = ? AND done_at IS NULL", [$e]), 'calls nobody for a bag delivered');
        same(1, Delivery::settle($courier), 'the courier hands in its money');
        same('paid', Orders::get($e)['status']);
    },
];
