<?php
/**
 * The tenth audit (2026-09-29). Four of its eight findings were the same rule as before — a condition read before the
 * write lock — in places the earlier fixes had not reached. So the rule is now enforced, not remembered: Orders::editable()
 * refuses outside a transaction, and every change to a bill, a kitchen plate or the drawer reads what it depends on inside
 * the lock that writes it. Each finding is raced here by two real processes, and the guard itself is tested.
 * The others: the online customer's sessions and reset code (the staff rules, carried over), pay terms kept where they
 * change and replicated, and an upgrade that reads the rows instead of guessing (022).
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Settings};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Online\Accounts;
use Sofrexa\Modules\Orders\{Orders, Shifts};
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\Setup\Seed;
use Sofrexa\Sync\Apply;

$as = static function (string $uid): void {
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $pizza = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Salon']]), '5');
    Shifts::open(['TRY' => 0]);
    return ['boss' => $boss, 'pizza' => $pizza, 'table' => $table];
};
$race = static function (array $ops, array $args): array {
    $dir = (string) App::config('storage');
    $key = bin2hex(random_bytes(3));
    $cfg = "$dir/race-$key.php";
    file_put_contents($cfg, '<?php return ' . var_export(['db' => App::config('db'), 'storage' => $dir, 'role' => 'pc', 'debug' => true,
        'sync' => ['remote_url' => '', 'key' => '']], true) . ';');
    $child = "$dir/race10-child.php";
    file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);
putenv('SOFREXA_CONFIG=' . $argv[1]);
require $argv[2] . '/bootstrap.php';
$a = json_decode($argv[3], true);
\Sofrexa\Core\Auth::actAs(\Sofrexa\Core\Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$a['user']]));
file_put_contents($argv[4], 'ready');
try {
    $O = \Sofrexa\Modules\Orders\Orders::class;
    $r = match ($argv[5]) {
        'move' => \Sofrexa\Modules\Orders\Shifts::move('in', 'TRY', 10000, 'test'),
        'close' => \Sofrexa\Modules\Orders\Shifts::close(['TRY' => 0]),
        'qty' => $O::updateLine($a['line'], 2.0),
        'send' => $O::send($a['order']),
        'voidline' => $O::voidLine($a['line'], 'test'),
        'cleardisc' => $O::clearDiscount($a['order']),
        'payall' => $O::pay($a['order'], [['method' => 'cash', 'amount' => $a['amount']]], null, false),
        'table' => $O::forTable($a['table']),
        'reset' => \Sofrexa\Modules\Online\Accounts::reset($a['email'], $a['code'], 'Sifre-' . $argv[6] . '-2026', 'Sifre-' . $argv[6] . '-2026'),
    };
    echo json_encode(['ok' => true, 'r' => $r]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
PHP);
    $args += ['user' => Auth::user()['id']];
    $pdo = Db::pdo();
    $pdo->exec('BEGIN IMMEDIATE');
    $procs = [];
    foreach ($ops as $i => $op) {
        $procs[] = proc_open([PHP_BINARY, $child, $cfg, APP_DIR, json_encode($args), "$dir/race-$key-$i", $op, (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    for ($t = 0; $t < 300 && count(array_filter(array_keys($ops), static fn(int $i): bool => is_file("$dir/race-$key-$i"))) < count($ops); $t++) {
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
/** Plays $round until both orders of the two operations were seen (the first one winning, then the other). */
$bothWays = static function (callable $round, int $max = 12): void {
    $seen = [];
    for ($i = 0; $i < $max && count($seen) < 2; $i++) {
        $seen[$round() ? 'first' : 'second'] = true;
    }
    same(2, count($seen), 'both orders were played');
};
/** A bill with one pizza, sent. */
$bill = static function (array $s, bool $send = true): array {
    $o = Orders::create('table', ['table_id' => $s['table']]);
    $l = Orders::addItem($o, $s['pizza']);
    if ($send) {
        Orders::send($o);
    }
    return [$o, $l];
};

return [
    'the guard: a bill is changed only where it is read — editable() refuses outside a transaction' => function () use ($setup, $bill): void {
        $s = $setup();
        [$o] = $bill($s);
        try {
            Orders::editable($o);
            check(false, 'refused');
        } catch (\LogicException) {
        }
        same($o, Db::tx(static fn(): string => Orders::editable($o)['id']), 'inside one it is the bill');
        same($o, Orders::openBill($o)['id'], 'to look at it, openBill()');
    },

    '3 a drawer move and the shift closing at once: the Z counts it, or it is refused' => function () use ($setup, $race, $bothWays): void {
        $setup();
        $bothWays(static function () use ($race): bool {
            Shifts::currentId() ?: Shifts::open(['TRY' => 0]);
            $r = $race(['move', 'close'], []);
            foreach (Db::rows('SELECT id, expected FROM shifts WHERE closed_at IS NOT NULL') as $z) {
                same((int) json_arr((string) $z['expected'])['TRY'], (int) Shifts::summary($z['id'])['cash']['TRY'], 'a closed shift adds up to its Z');
            }
            return $r[0]['ok'];
        });
    },

    '4 a quantity changed while the line is sent: the bill, the ticket and the stock agree' => function () use ($setup, $race, $bothWays, $bill): void {
        $s = $setup();
        $un = Db::save('stock_items', ['name' => 'Un', 'unit' => 'kg', 'avg_cost' => 100]);
        Db::append('stock_moves', ['stock_item_id' => $un, 'qty' => 100, 'unit_cost' => 100, 'reason' => 'purchase', 'at' => Clock::ms()]);
        Stock::setRecipe('item', $s['pizza'], [['stock_item_id' => $un, 'qty' => '1']]);
        $bothWays(static function () use ($race, $bill, $s, $un): bool {
            [$o, $l] = $bill($s, false);
            $r = $race(['qty', 'send'], ['order' => $o, 'line' => $l]);
            $line = Orders::line($l);
            same('sent', $line['status']);
            same((float) $line['qty'], (float) $line['sent_qty'], 'sent as many as the bill says');
            same(-(float) $line['qty'], (float) Db::value("SELECT SUM(qty) FROM stock_moves WHERE order_item_id = ? AND reason = 'sale'", [$l]), 'and took that much stock');
            return $r[0]['ok'] && (float) $line['qty'] === 2.0;
        });
    },

    '5 a void or a discount taken off while the bill is paid: a paid bill keeps what it was paid for' => function () use ($setup, $race, $bothWays, $bill): void {
        $s = $setup();
        $check = static function (): void {
            foreach (Db::rows("SELECT id, total, paid FROM orders WHERE status = 'paid'") as $o) {
                same((int) $o['total'], (int) $o['paid'], 'a paid bill owes what was paid');
            }
        };
        $bothWays(static function () use ($race, $bill, $s, $check): bool {
            [$o, $l] = $bill($s);
            $r = $race(['voidline', 'payall'], ['order' => $o, 'line' => $l, 'amount' => 10000]);
            $check();
            return $r[0]['ok'];
        });
        $bothWays(static function () use ($race, $bill, $s, $check): bool {
            [$o] = $bill($s);
            Orders::discount($o, 'pct', 10);
            $r = $race(['cleardisc', 'payall'], ['order' => $o, 'amount' => 9000]);
            $check();
            return $r[0]['ok'] && Orders::get($o)['status'] === 'open';
        });
    },

    '6 two waiters open the same empty table at once: one bill, and every bill its own number' => function () use ($setup, $race): void {
        $s = $setup();
        for ($i = 0; $i < 3; $i++) {
            $t = Floor::addTable(Db::value('SELECT area_id FROM tables WHERE id = ?', [$s['table']]), (string) (10 + $i));
            $race(['table', 'table'], ['table' => $t]);
            same(1, (int) Db::value("SELECT COUNT(*) FROM orders WHERE table_id = ? AND status = 'open'", [$t]));
        }
        same(0, (int) Db::value('SELECT COUNT(*) FROM (SELECT day, no FROM orders GROUP BY day, no HAVING COUNT(*) > 1)'), 'no number twice');
    },

    '7 8 an online customer\'s new password ends the old sessions; one reset code sets one password' => function () use ($setup, $race): void {
        $setup();
        $c = Db::save('customers', ['name' => 'Ayşe']);
        $code = '123456';
        $a = Db::save('online_accounts', ['customer_id' => $c, 'email' => 'ayse@example.com', 'password_hash' => password_hash('Eski-sifre-2026', PASSWORD_DEFAULT),
            'verified_at' => Clock::ms(), 'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'code_purpose' => 'reset', 'code_expires' => Clock::ms() + 600_000]);
        $_SESSION = [];
        Accounts::signIn(Accounts::get($a), true);
        $old = $_SESSION;
        same($a, Accounts::current()['id'] ?? null, 'signed in');
        $r = $race(['reset', 'reset'], ['email' => 'ayse@example.com', 'code' => $code]);
        same(1, count(array_filter($r, static fn(array $x): bool => $x['ok'])), 'one of the two');
        $winner = $r[0]['ok'] ? 0 : 1;
        check(password_verify('Sifre-' . $winner . '-2026', (string) Db::value('SELECT password_hash FROM online_accounts WHERE id = ?', [$a])), 'the password of the one that took the code');
        $_SESSION = $old;
        same(null, Accounts::current(), 'the session from before the reset is over');
        $_SESSION = ['online' => $a];
        same(null, Accounts::current(), 'and one with no stamp at all');
        $_SESSION = [];
    },

    '2 pay terms reach the other copy step by step: two raises before a sync, a name changed after one' => function () use ($setup): void {
        Clock::freeze((int) strtotime('2026-07-10 12:00') * 1000);
        $setup();
        $w = Seed::user('Garson', 'waiter', '2345');
        $role = Db::value('SELECT role_id FROM users WHERE id = ?', [$w]);
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '1.000']);
        $first = (string) App::config('db');
        $second = substr($first, 0, -7) . '-2.sqlite';
        @unlink($second);
        Db::exec('VACUUM INTO ' . Db::pdo()->quote($second));
        Db::exec('DELETE FROM sync_outbox');
        App::setConfig('sync.remote_url', 'http://web.example');
        Clock::freeze((int) strtotime('2026-08-10 12:00') * 1000);
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '2.000']);
        Clock::freeze((int) strtotime('2026-09-02 12:00') * 1000);
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '2.500']);
        Db::save('users', ['id' => $w, 'name' => 'Garson Ali']); // and the name, after
        $earned = static fn(string $m): int => (int) array_column(Staff::payroll($m), 'earned', 'id')[$w];
        $here = [$earned('2026-07'), $earned('2026-08'), $earned('2026-09')];
        $rows = Apply::collect(Db::rows('SELECT * FROM sync_outbox ORDER BY seq'));
        App::setConfig('sync.remote_url', '');
        Db::disconnect();
        App::setConfig('db', $second);
        App::setConfig('role', 'web');
        Settings::flush();
        Clock::freeze((int) strtotime('2026-09-20 12:00') * 1000);
        try {
            Apply::rows($rows);
        } finally {
            App::setConfig('role', 'pc');
        }
        same([100000, 200000, 250000], $here);
        same($here, [$earned('2026-07'), $earned('2026-08'), $earned('2026-09')], 'the other copy, told in September');
    },
];
