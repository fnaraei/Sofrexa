<?php
/**
 * The ninth audit (2026-09-29). What passed every test before came from three things no test played:
 *   - two copies (the till PC and the web copy) that learn each other's changes by replication;
 *   - what was made before an upgrade (a session opened by the older version);
 *   - two different operations at the same moment (a payment while the shift closes, a dish while the bag leaves).
 * So each is tested as a rule here: a guard that no code writes a replicated row the other copy never hears of; changes
 * replayed on a second database; two real processes racing different operations. And the shortcuts that were bugs:
 * the board's time window (R05) and one queue for every printer (R04, R07).
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Settings, Sync, View};
use Sofrexa\Modules\Kitchen\Kitchen;
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Delivery, Orders, Shifts};
use Sofrexa\Modules\Staff\{Staff, Users};
use Sofrexa\Print\Spooler;
use Sofrexa\Setup\Seed;
use Sofrexa\Sync\Apply;

$as = static function (string $uid): void {
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]));
};
$setup = static function (bool $shift = true) use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $as($boss);
    $cat = Db::save('categories', ['names' => ['tr' => 'Mutfak'], 'station' => 'kitchen', 'vat_rate' => 10]);
    $pizza = Menu::saveItem(['names' => ['tr' => 'Pizza'], 'category_id' => $cat, 'price' => '100', 'available' => 1]);
    $table = Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Salon']]), '5');
    if ($shift) {
        Shifts::open(['TRY' => 0]);
    }
    return ['boss' => $boss, 'pizza' => $pizza, 'table' => $table];
};
/**
 * A second copy: this database as it is now, in another file. $then runs on the first copy with replication recording;
 * what it recorded is applied to the second, which stays selected afterwards (as the web copy, or the PC).
 */
$twoCopies = static function (callable $then, string $secondRole = 'web', ?int $secondClock = null): void {
    $first = (string) App::config('db');
    $second = substr($first, 0, -7) . '-2.sqlite';
    @unlink($second);
    Db::exec('VACUUM INTO ' . Db::pdo()->quote($second));
    Db::exec('DELETE FROM sync_outbox');
    App::setConfig('sync.remote_url', 'http://web.example'); // the first copy records what changes
    $then();
    $rows = Apply::collect(Db::rows('SELECT * FROM sync_outbox ORDER BY seq'));
    App::setConfig('sync.remote_url', '');
    Db::disconnect();
    App::setConfig('db', $second);
    App::setConfig('role', $secondRole);
    Settings::flush();
    Clock::freeze($secondClock);
    try {
        Apply::rows($rows);
    } finally {
        App::setConfig('role', 'pc');
    }
};
/** Two real processes, each doing its own operation at the same moment, held behind this process's write lock. */
$race = static function (array $ops, array $args): array {
    $dir = (string) App::config('storage');
    $key = bin2hex(random_bytes(3));
    $cfg = "$dir/race-$key.php";
    file_put_contents($cfg, '<?php return ' . var_export(['db' => App::config('db'), 'storage' => $dir, 'role' => 'pc', 'debug' => true,
        'sync' => ['remote_url' => '', 'key' => '']], true) . ';');
    $child = "$dir/race9-child.php";
    file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);
putenv('SOFREXA_CONFIG=' . $argv[1]);
require $argv[2] . '/bootstrap.php';
$a = json_decode($argv[3], true);
\Sofrexa\Core\Auth::actAs(\Sofrexa\Core\Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$a['user']]));
file_put_contents($argv[4], 'ready');
try {
    $r = match ($argv[5]) {
        'pay' => \Sofrexa\Modules\Orders\Orders::pay($a['order'], [['method' => 'cash', 'amount' => $a['amount']]], null, false),
        'close' => \Sofrexa\Modules\Orders\Shifts::close(['TRY' => 0]),
        'add' => \Sofrexa\Modules\Orders\Orders::addItem($a['order'], $a['item']),
        'way' => \Sofrexa\Modules\Orders\Delivery::move($a['order'], 'way'),
    };
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
PHP);
    $args += ['user' => Auth::user()['id']];
    $pdo = Db::pdo();
    $pdo->exec('BEGIN IMMEDIATE');
    $procs = [];
    foreach ($ops as $i => $op) {
        $procs[] = proc_open([PHP_BINARY, $child, $cfg, APP_DIR, json_encode($args), "$dir/race-$key-$i", $op], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
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

return [
    'a guard: no code writes a replicated row the other copy never hears of' => function (): void {
        // every write to a replicated table goes through Db::save / append / softDelete, which record it for the other copy
        // — never a bare UPDATE, DELETE or insert. The few below are deliberate, each for its reason.
        $allowed = [
            'Core/Auth.php:users' => 'failed PIN tries and the lock-out are this device\'s own',
            'Migrate/ItKafeImport.php:orders' => 'the import fills a new database before its first sync (a full snapshot)',
            'Migrate/ItKafeImport.php:items' => 'the same',
            'Setup/Nightly.php:notifications' => 'each copy clears its own read notices',
        ];
        $replicated = [...Sync::MUTABLE, ...Sync::APPEND];
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_DIR . '/src', FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php' || str_ends_with($f->getPathname(), 'Db.php')) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            preg_match_all("/Db::(?:update|insert)\\(\\s*'(\\w+)'|Db::exec\\(\\s*[\"'](?:UPDATE|DELETE FROM|INSERT(?: OR \\w+)? INTO)\\s+(\\w+)/i", $src, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $table = $hit[1] !== '' ? $hit[1] : $hit[2];
                $where = str_replace('\\', '/', substr($f->getPathname(), strlen(APP_DIR . '/src/'))) . ':' . $table;
                if (in_array($table, $replicated, true) && !isset($allowed[$where])) {
                    $found[] = $where;
                }
            }
        }
        same([], array_values(array_unique($found)), 'a bare write to a replicated table');
    },

    'S02 a password set on one copy ends the other links on both, the used one too' => function () use ($setup, $twoCopies): void {
        $setup();
        $u = Seed::user('Ayşe', 'cashier', '2345');
        $link = static function () use ($u): string {
            $t = bin2hex(random_bytes(8));
            Db::save('password_resets', ['user_id' => $u, 'token_hash' => hash('sha256', $t), 'expires_at' => Clock::ms() + 3_600_000]);
            return $t;
        };
        [$old, $new, $third] = [$link(), $link(), $link()];
        $twoCopies(static function () use ($new): void {
            Users::setPassword(Users::resetByToken($new), 'yeni-sifre-2026');
        });
        same([null, null, null], [Users::resetByToken($old), Users::resetByToken($new), Users::resetByToken($third)], 'on the second copy');
    },

    'S02 a new PIN ends the password links still out' => function () use ($setup): void {
        $setup();
        $u = Seed::user('Ayşe', 'cashier', '2345');
        $t = bin2hex(random_bytes(8));
        Db::save('password_resets', ['user_id' => $u, 'token_hash' => hash('sha256', $t), 'expires_at' => Clock::ms() + 3_600_000]);
        Users::resetPin($u);
        same(null, Users::resetByToken($t));
    },

    'S03 a session from before the credentials stamp signs in again' => function () use ($setup): void {
        $setup();
        $u = Seed::user('Ayşe', 'cashier', '2345');
        $loaded = new ReflectionProperty(Auth::class, 'loaded');
        Auth::actAs(null);
        $_SESSION = ['uid' => $u, 'pwv' => null, 'kind' => 'pin']; // what the older version kept
        $loaded->setValue(null, false);
        same(null, Auth::user(), 'not taken on trust');
        same(false, isset($_SESSION['uid']), 'and ended');
        $_SESSION = [];
    },

    'R01 a payment and the shift closing at the same moment: the Z counts the payment, or the payment is refused' => function () use ($setup, $race): void {
        $s = $setup();
        $seen = [];
        for ($round = 0; $round < 12 && count($seen) < 2; $round++) {
            Shifts::currentId() ?: Shifts::open(['TRY' => 0]);
            $o = Orders::create('table', ['table_id' => $s['table']]);
            Orders::addItem($o, $s['pizza']);
            Orders::send($o);
            $r = $race(['pay', 'close'], ['order' => $o, 'amount' => 10000]);
            $seen[$r[0]['ok'] ? 'paid first' : 'closed first'] = true; // until both orders have been played
            foreach (Db::rows('SELECT id, expected FROM shifts WHERE closed_at IS NOT NULL') as $z) {
                same((int) json_arr((string) $z['expected'])['TRY'], (int) Shifts::summary($z['id'])['cash']['TRY'], 'a closed shift adds up to its Z');
            }
            same(0, (int) Db::value('SELECT COUNT(*) FROM payments p JOIN shifts s ON s.id = p.shift_id WHERE p.at > s.closed_at'), 'nothing paid into a shift after it closed');
        }
        same(2, count($seen), 'both orders were played');
    },

    'R02 a dish added while the bag leaves: it joins before, or is refused — never a new dish on a bag on its way' => function () use ($setup, $race): void {
        $s = $setup();
        $courier = Seed::user('Emre', 'courier', '6060');
        for ($round = 0; $round < 3; $round++) {
            $d = Delivery::create(['type' => 'delivery', 'phone' => '0533 000 00 01', 'name' => 'Ali', 'address' => 'Adres 1', 'courier_id' => $courier, 'pay' => 'cash',
                'items' => [['item_id' => $s['pizza'], 'qty' => 1]]]);
            Kitchen::ready($d, 1, 'kitchen');
            $race(['add', 'way'], ['order' => $d, 'item' => $s['pizza']]);
            $gone = in_array(json_arr((string) Db::value('SELECT delivery FROM orders WHERE id = ?', [$d]))['stage'] ?? '', ['way', 'done'], true);
            $cooking = (int) Db::value("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status IN ('new', 'sent') AND deleted = 0", [$d]);
            check(!($gone && $cooking), 'a bag on its way with a dish still to cook');
        }
    },

    'R03 a raise made in August and synced in September is August\'s on both copies' => function () use ($setup, $as, $twoCopies): void {
        Clock::freeze((int) strtotime('2026-07-10 12:00') * 1000);
        $setup();
        $w = Seed::user('Garson', 'waiter', '2345');
        $role = Db::value('SELECT role_id FROM users WHERE id = ?', [$w]);
        Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '1.000']);
        $earned = static fn(string $m): int => (int) array_column(Staff::payroll($m), 'earned', 'id')[$w];
        $twoCopies(static function () use ($w, $role): void {
            Clock::freeze((int) strtotime('2026-08-10 12:00') * 1000);
            Staff::saveProfile($w, ['role_id' => $role, 'base_salary' => '2.000']);
        }, 'web', (int) strtotime('2026-09-05 12:00') * 1000);
        $second = [$earned('2026-07'), $earned('2026-08')];
        same([100000, 200000], $second, 'the second copy, told in September');
    },

    'R04 R07 one printer off does not stop another, and two outputs of one printer keep their order' => function () use ($setup): void {
        $setup();
        Clock::freeze(1_790_593_200_000);
        Settings::set('printer.kitchen', ['driver' => 'tcp', 'target' => '']);
        Settings::set('printer.cashier', ['driver' => 'file', 'target' => '']);
        for ($i = 0; $i < 210; $i++) {
            Db::insert('print_jobs', ['id' => sprintf('k%05d', $i), 'printer' => 'kitchen', 'kind' => 'kitchen', 'payload' => base64_encode('X'), 'at' => Clock::ms() - 1000 + $i]);
        }
        Spooler::print('cashier', 'receipt', 'FİŞ');
        Spooler::work();
        same('done', Db::value("SELECT status FROM print_jobs WHERE kind = 'receipt'"), 'the receipt printed');
        // the bar prints on the till's printer: an older till ticket waiting for its retry holds a newer bar ticket
        Settings::set('printer.bar_on', 'cashier');
        Settings::set('printer.cashier', ['driver' => 'tcp', 'target' => '']);
        Spooler::print('cashier', 'receipt2', 'OLD');
        Spooler::work();
        Settings::set('printer.cashier', ['driver' => 'file', 'target' => '']);
        Spooler::print('bar', 'bar', 'NEW');
        Clock::freeze(Clock::ms() + 200);
        Spooler::work();
        same(['retry', 'queued'], [Db::value("SELECT status FROM print_jobs WHERE kind = 'receipt2'"), Db::value("SELECT status FROM print_jobs WHERE kind = 'bar'")]);
        Clock::freeze(Clock::ms() + 60_000);
        Spooler::work();
        same(['receipt2', 'bar'], array_column(Db::rows("SELECT kind FROM print_jobs WHERE kind IN ('receipt2', 'bar') ORDER BY done_at, rowid"), 'kind'));
    },

    'R05 a paid bag stays on the board until it is handed over, however long; one closed before 021 does not come back' => function () use ($setup): void {
        $s = $setup();
        $d = Delivery::create(['type' => 'delivery', 'phone' => '0533 000 00 01', 'name' => 'Ali', 'address' => 'Adres 1', 'pay' => 'card', 'items' => [['item_id' => $s['pizza'], 'qty' => 1]]]);
        Orders::pay($d, [['method' => 'card', 'amount' => (int) Orders::get($d)['total']]], null, false);
        Clock::freeze(Clock::ms() + 30 * 3_600_000);
        check(in_array($d, array_column(Delivery::open(), 'id'), true), 'still waiting, thirty hours on');
        // as 021 found it: a paid take-away from before, its dish never marked carried out
        $old = Orders::create('takeaway', ['label' => 'Eski']);
        Orders::addItem($old, $s['pizza']);
        Orders::send($old);
        Db::exec("UPDATE orders SET status = 'paid', closed_at = ? WHERE id = ?", [Clock::ms(), $old]);
        Db::exec("DELETE FROM schema_migrations WHERE name = '021_sync_safe'");
        Db::exec('ALTER TABLE orders DROP COLUMN handed_at');
        \Sofrexa\Core\Migrator::run();
        check(!in_array($old, array_column(Delivery::open(), 'id'), true), 'the old bill stays ended');
    },

    'R06 at 01:30 the forms suggest the business day, not the calendar day' => function () use ($setup): void {
        $setup();
        Clock::freeze((int) strtotime('2026-09-29 01:30') * 1000);
        $html = View::render('finance/form', ['title' => 'Gider', 'back' => '/finance', 'kind' => 'expense', 'cats' => \Sofrexa\Modules\Finance\Finance::categories(), 'currencies' => ['TRY'], 'shift' => Shifts::currentId()], null);
        check(str_contains($html, 'value="2026-09-28"'), 'the day still running');
    },
];
