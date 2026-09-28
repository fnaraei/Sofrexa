<?php
declare(strict_types=1);

namespace Sofrexa\Migrate;

use Sofrexa\Core\{Clock, Db, Settings, Sync};
use Sofrexa\Setup\Seed;

/**
 * ItKafe → Sofrexa history import (php app/bin/sofrexa import:itkafe --db=<new.sqlite>).
 *
 * Reads the ItKafe SQL Server database (SELECT only, through ItKafeSource) and writes the floor, staff, menu,
 * customers and suppliers, orders with lines, discounts, payments and voids, customer ledgers and bonus, till cash
 * moves, stock, recipes, attendance and payroll into the current Sofrexa database. The mapping follows the ItKafe
 * import spec; docs/itkafe-schema.md describes the source tables.
 *
 * Re-runs: every row gets a deterministic id (UUID v7 of the row's time and a hash of its ItKafe key) and, where the
 * table has the column, legacy_id "itk:<kind>:<key>". Rows go in with INSERT OR IGNORE, so a run with append on the
 * same file only adds what is missing. Writes use prepared statements in transactions of BATCH rows, without sync
 * records (the first sync to the web copy sends a full snapshot anyway).
 * Money is integer kuruş, rounded in SQL (ROUND(x * 100)); the business day is ItKafe's own (D_Dat, yyyymmdd).
 */
final class ItKafeImport
{
    /** Default time zone of the till PC (see run()). */
    public const TZ = 'Europe/Istanbul';

    private const BATCH = 5000;
    /** Time part of the ids of rows without a date (2019-05-01, the start of the data). */
    private const EPOCH = 1_556_668_800_000;
    /** "Guest": the walk-in default, imported as no customer. */
    private const WALK_IN = 3;
    /** The restaurant itself (write-off documents). */
    private const OWN_COMPANY = 2;
    private const SUPPLIER_GROUP = 121;
    /** Yemek sepeti accounts: their orders are deliveries (rung on the take-away tables). */
    private const DELIVERY = [960, 973, 987];
    private const TAKEAWAY = [97, 82];
    /** HV_Key 35104: −4,383,000 TL typed by mistake on 2022-05-26; left out. */
    private const PAYROLL_TYPO = 35104;
    /** R_Menu.Virt_Key → station (3 cook, 2 bar, 4 gift shop). */
    private const STATION = [3 => 'kitchen', 2 => 'bar', 4 => 'bar'];
    /** OplataVid → method. 8/9/10 (dollar, euro, pound: 5 payments, 30 TL, no rate kept) are kept as cash with a note. */
    private const METHOD = [1 => 'cash', 2 => 'card', 3 => 'account', 8 => 'cash', 9 => 'cash', 10 => 'cash'];
    private const FX = [8 => 'USD', 9 => 'EUR', 10 => 'GBP'];
    /** A shift longer than this is a forgotten clock-out. */
    private const MAX_SHIFT_MS = 20 * 3_600_000;

    private \Closure $out;
    private \DateTimeZone $tz;
    private int $fromDat = 0;
    private int $fromMs = 0;
    private int $now = 0;
    private int $firstDat = 0;
    private int $lastDat = 0;
    /** @var array<string, int> "Y-m-dTH" → ms of that local hour */
    private array $hours = [];
    /** @var array<string, \PDOStatement> */
    private array $stmts = [];
    /** @var array<string, int> rows inserted per table */
    private array $added = [];
    /** ItKafe key → Sofrexa id */
    private array $staff = [];
    private array $customers = [];
    private array $suppliers = [];
    private array $tables = [];
    private array $tableNames = [];
    /** Write-off tables (St_Fl_Spisanie = 1): St_Key → finance category. */
    private array $nonSales = [];
    /** Mn_Key → [id, name, station, own (created here), active] */
    private array $items = [];
    /** A_Key → [id, cost (kuruş per unit), qty (ItKafe on hand)] */
    private array $stock = [];
    /** idZakaz → order id */
    private array $orders = [];
    /** idZakaz of orders whose payments stay out (write-off tables, orders left only as deleted lines). */
    private array $noPay = [];
    /** Write-off table spending: St_Key → day → [orders, kuruş] */
    private array $spend = [];

    public function __construct(private ItKafeSource $src, callable $say)
    {
        $this->out = \Closure::fromCallable($say);
        $this->tz = new \DateTimeZone(self::TZ);
    }

    /**
     * Imports the history. $opt: from (Y-m-d: orders and money from this business day on; balances before it become
     * an opening row), append (add what is missing to a database that already holds an import), tz (the till PC's zone).
     */
    public function run(array $opt = []): void
    {
        $t0 = microtime(true);
        // ItKafe stores the naive wall clock of the till PC, with no zone. The data cannot tell Istanbul time (UTC+3 all
        // year) from EET/EEST (Europe/Nicosia, like the app's Asia/Famagusta): they differ by 1 h in winter. Europe/Istanbul
        // is the default; check the till PC's Windows time zone and pass tz if it differs, the same on every append run
        // (ids include the time). Business days do not depend on it: they come from ItKafe itself (D_Dat).
        $this->tz = new \DateTimeZone((string) (($opt['tz'] ?? '') ?: self::TZ));
        $from = trim((string) ($opt['from'] ?? ''));
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            throw new \InvalidArgumentException('from must be YYYY-MM-DD');
        }
        $this->fromDat = $from === '' ? 0 : (int) str_replace('-', '', $from);
        $this->fromMs = $from === '' ? 0 : (new \DateTimeImmutable($from . ' 05:00:00', $this->tz))->getTimestamp() * 1000;
        if (empty($opt['append']) && Db::value("SELECT 1 FROM users WHERE legacy_id LIKE 'itk:%' UNION ALL SELECT 1 FROM orders WHERE legacy_id LIKE 'itk:%' LIMIT 1")) {
            throw new \RuntimeException('This database already holds an ItKafe import: use a new file, or --append to add what is missing.');
        }
        $this->now = Clock::ms();
        $this->say('ItKafe → Sofrexa' . ($from !== '' ? ", from $from" : '') . ', time zone ' . $this->tz->getName());
        Sync::muted(function (): void {
            $this->base();
            $this->floor();
            $this->staff();
            $this->menu();
            $this->people();
            $this->orders();
            $this->lines();
            $this->payments();
            $this->nonSales();
            $this->ledger();
            $this->cash();
            $this->stock();
            $this->recipes();
            $this->attendance();
            $this->payroll();
            $this->numbers();
        });
        $this->say(sprintf('Done in %.1f s.', microtime(true) - $t0));
    }

    // ------------------------------------------------------------ master data

    private function base(): void
    {
        if (!Db::value('SELECT 1 FROM roles WHERE deleted = 0 LIMIT 1')) {
            Seed::base(static fn(string $m) => null);
            $this->say('roles and loyalty tiers created');
        }
        $this->firstDat = (int) $this->src->value('SELECT MIN(D_Dat) v FROM R_Doc_Down WHERE D_Dat > 0');
        $this->lastDat = (int) $this->src->value('SELECT MAX(D_Dat) v FROM R_Doc_Down');
        $this->nonSales = $this->nonSalesTables();
        $this->say(sprintf('business days %s … %s', self::day($this->firstDat), self::day($this->lastDat)));
    }

    /**
     * Write-off tables (R_Stol_Type.St_Fl_Spisanie = 1), which every ItKafe report leaves out: St_Key → finance
     * category (type 1 service = staff meals → staff; 2 boss and 4 terminal → other). Here: 98 and 81.
     */
    private function nonSalesTables(): array
    {
        $out = [];
        foreach ($this->src->rows('SELECT r.St_Key k, t.St_Type t FROM R_Stol r JOIN R_Stol_Type t ON t.St_Type = r.St_Type WHERE t.St_Fl_Spisanie = 1', ['k', 't']) as $r) {
            $out[(int) $r['k']] = (int) $r['t'] === 1 ? 'staff' : 'other';
        }
        return $out;
    }

    /** Areas and tables, matched to an existing floor plan by name / number. */
    private function floor(): void
    {
        $known = [];
        foreach (Db::rows('SELECT id, name, names FROM areas WHERE deleted = 0') as $a) {
            foreach ([$a['name'], ...array_values(json_arr($a['names']))] as $n) {
                $known[self::norm((string) $n)] ??= $a['id'];
            }
        }
        $areas = [];
        $this->each($this->src->rows('SELECT idZona k, ZonaName n FROM R_StolZona', ['k', 'n']), function (array $r) use ($known, &$areas): void {
            $name = self::text($r['n']) ?? 'Salon';
            $id = $known[self::norm($name)] ?? self::uuid(self::EPOCH, 'itk:area:' . $r['k']);
            $this->put('areas', ['id' => $id, 'name' => $name, 'names' => self::names($name), 'sort' => (int) $r['k'] * 10, 'updated_at' => $this->now]);
            $areas[(int) $r['k']] = $id;
        });
        if (!$areas) {
            $areas[0] = self::uuid(self::EPOCH, 'itk:area:0');
            $this->put('areas', ['id' => $areas[0], 'name' => 'Salon', 'names' => self::names('Salon'), 'sort' => 0, 'updated_at' => $this->now]);
        }
        $known = [];
        foreach (Db::rows('SELECT id, number FROM tables WHERE deleted = 0') as $t) {
            $known[self::norm((string) $t['number'])] ??= $t['id'];
        }
        $this->each($this->src->rows('SELECT St_Key k, St_Name n, idZona z, CAST(St_Active AS int) a FROM R_Stol', ['k', 'n', 'z', 'a']), function (array $r) use ($known, $areas): void {
            $key = (int) $r['k'];
            $name = self::text($r['n']) ?? (string) $key;
            $number = ctype_digit($name) ? (ltrim($name, '0') ?: '0') : $name;
            $this->tableNames[$key] = $name;
            // take-away and write-off "tables" are not seats: kept for history, hidden from the floor plan.
            // Seats are not stored in ItKafe; the column's default (4) stays.
            $seat = !in_array($key, self::TAKEAWAY, true) && !isset($this->nonSales[$key]);
            $id = $known[self::norm($number)] ?? self::uuid(self::EPOCH, 'itk:table:' . $key);
            $this->put('tables', ['id' => $id, 'area_id' => $areas[(int) $r['z']] ?? reset($areas), 'number' => $number,
                'sort' => ctype_digit($number) ? (int) $number * 10 : 9000 + $key, 'active' => $seat ? (int) $r['a'] : 0, 'updated_at' => $this->now]);
            $this->tables[$key] = $id;
        });
        $this->report('areas', 'tables');
    }

    private function staff(): void
    {
        $roles = Db::pairs('SELECT code, id FROM roles WHERE deleted = 0');
        $known = [];
        foreach (Db::rows("SELECT id, name FROM users WHERE deleted = 0 AND (legacy_id IS NULL OR legacy_id NOT LIKE 'itk:%')") as $u) {
            $known[self::norm($u['name'])] ??= $u['id'];
        }
        $cashiers = [];
        foreach ($this->src->rows('SELECT DISTINCT No_People p FROM AccessPeople WHERE KeyAccess = 4', ['p']) as $r) {
            $cashiers[(int) $r['p']] = true;
        }
        // active = worked a shift in the last 91 days of the data (the spec's "since 2025-08-25"); W_Fl_Uvolen is rarely set
        $activeFrom = (int) (new \DateTimeImmutable((string) self::day($this->lastDat)))->modify('-91 days')->format('Ymd');
        $sql = 'SELECT w.No_People p, w.W_Name1 n1, w.W_Name2 n2, w.W_Nick nick, w.W_Phone phone, w.Key_DolgnostNew post, CAST(w.Admin AS int) adm,
                CAST(ROUND(w.Job_StavkaNew * 100, 0) AS bigint) rate, CONVERT(varchar(10), w.W_DateNayma, 126) hired,
                CONVERT(varchar(23), w.W_DataCreate, 126) created, s.last
            FROM Z_WorkMans w LEFT JOIN (SELECT Key_Job, MAX(Dat) last FROM Z_Smena_History WHERE Dat <= ' . $this->lastDat . ' GROUP BY Key_Job) s ON s.Key_Job = w.No_People';
        $this->each($this->src->rows($sql, ['p', 'n1', 'n2', 'nick', 'phone', 'post', 'adm', 'rate', 'hired', 'created', 'last']), function (array $r) use ($roles, $known, $cashiers, $activeFrom): void {
            $p = (int) $r['p'];
            $name = self::text(trim($r['n1'] . ' ' . $r['n2'])) ?? self::text($r['nick']) ?? 'Personel ' . $p;
            $id = $known[self::norm($name)] ?? self::uuid($this->ms($r['created']) ?? self::EPOCH, 'itk:user:' . $p);
            $role = self::role((int) $r['post'], (bool) (int) $r['adm'], isset($cashiers[$p]));
            $this->put('users', [
                'id' => $id, 'name' => mb_substr($name, 0, 120), 'phone' => self::text($r['phone']), 'role_id' => $roles[$role] ?? $roles['waiter'],
                'active' => (int) $r['last'] >= $activeFrom ? 1 : 0,
                // Job_StavkaNew as ItKafe has it: TL per hour (tariff 3, 42 people) or per shift (tariff 0/1/2); Sofrexa reads
                // base_salary as a monthly amount, so the manager sets the pay model again (reported by the import)
                'base_salary' => max(0, (int) $r['rate']), 'commission_pct' => 0,
                'hired_on' => $r['hired'] > '2000-01-01' ? $r['hired'] : null, 'legacy_id' => 'itk:user:' . $p, 'sort' => $p * 10,
                'created_at' => $this->ms($r['created']), 'updated_at' => $this->now,
            ]);
            $this->staff[$p] = $id;
        });
        $this->report('users');
    }

    /**
     * Categories (S_TreeView Dop_Par = 2, flattened) and items (R_Menu), matched to an existing menu by name.
     * What is created here stays off the website, the QR menu and online ordering.
     */
    private function menu(): void
    {
        $nodes = [];
        foreach ($this->src->rows('SELECT Key_Tree k, Tree_Name n, Key_Up u, KeyUpFirst r, LNum l FROM S_TreeView WHERE Dop_Par = 2', ['k', 'n', 'u', 'r', 'l']) as $r) {
            $nodes[(int) $r['k']] = ['name' => self::text($r['n']) ?? 'Menü', 'up' => (int) $r['u'], 'root' => (int) $r['r'], 'sort' => (int) $r['l'] * 10];
        }
        $menu = iterator_to_array($this->src->rows('SELECT Mn_Key k, Mn_Name n, Key_Tree c, CAST(ROUND(Mn_Price1 * 100, 0) AS bigint) price, CAST(Mn_Lock AS int) lck,
            Virt_Key v, Mn_Sort s, CONVERT(varchar(23), Mn_DataCreate, 126) created FROM R_Menu', ['k', 'n', 'c', 'price', 'lck', 'v', 's', 'created']), false);
        // station of a category: that of its items (= Virt_Key on every item), else of its root, else kitchen
        $votes = [];
        $active = [];
        foreach ($menu as $m) {
            $st = self::STATION[(int) $m['v']] ?? 'kitchen';
            $c = (int) $m['c'];
            $votes[$c][$st] = ($votes[$c][$st] ?? 0) + 1;
            $votes['r' . ($nodes[$c]['root'] ?? 0)][$st] = ($votes['r' . ($nodes[$c]['root'] ?? 0)][$st] ?? 0) + 1;
            if (!(int) $m['lck']) {
                $active[$c] = true;
            }
        }
        $top = static function (?array $v): ?string {
            if (!$v) {
                return null;
            }
            arsort($v);
            return (string) array_key_first($v);
        };
        // a name used twice (Soup, Salad, Other, Plates, Bar) gets its parent's name in front
        $count = array_count_values(array_map(static fn(array $n): string => self::norm($n['name']), $nodes));
        $knownCat = $this->nameIndex("SELECT id, names FROM categories WHERE deleted = 0 AND (legacy_id IS NULL OR legacy_id NOT LIKE 'itk:%')");
        $cats = [];
        $catStation = [];
        $this->each($nodes, function (array $n, int $k) use ($nodes, $count, $knownCat, $votes, $top, $active, &$cats, &$catStation): void {
            $name = $count[self::norm($n['name'])] > 1 && isset($nodes[$n['up']]) ? $nodes[$n['up']]['name'] . ' · ' . $n['name'] : $n['name'];
            $station = $top($votes[$k] ?? null) ?? $top($votes['r' . $n['root']] ?? null) ?? 'kitchen';
            $id = $knownCat[self::norm($name)] ?? self::uuid(self::EPOCH, 'itk:cat:' . $k);
            $this->put('categories', ['id' => $id, 'names' => self::names($name), 'station' => $station, 'vat_rate' => 0, 'sort' => $n['sort'],
                'active' => isset($active[$k]) ? 1 : 0, 'show_online' => 0, 'legacy_id' => 'itk:cat:' . $k, 'updated_at' => $this->now]);
            $cats[$k] = $id;
            $catStation[$k] = $station;
        });
        $knownItem = $this->nameIndex("SELECT id, names FROM items WHERE deleted = 0 AND (legacy_id IS NULL OR legacy_id NOT LIKE 'itk:%') ORDER BY active DESC");
        $this->each($menu, function (array $m) use ($cats, $catStation, $knownItem): void {
            $k = (int) $m['k'];
            $c = (int) $m['c'];
            $name = self::text($m['n']) ?? 'ItKafe #' . $k;
            $station = self::STATION[(int) $m['v']] ?? ($catStation[$c] ?? 'kitchen');
            $match = $knownItem[self::norm($name)] ?? null;
            $id = $match ?? self::uuid($this->ms($m['created']) ?? self::EPOCH, 'itk:item:' . $k);
            $this->items[$k] = ['id' => $id, 'name' => $name, 'station' => $station, 'own' => $match === null, 'active' => !(int) $m['lck']];
            if ($match !== null) {
                return;
            }
            $this->put('items', [
                'id' => $id, 'category_id' => $cats[$c] ?? $this->spareCategory(), 'names' => self::names($name), 'price' => (int) $m['price'], 'cost' => 0,
                'station' => $station !== ($catStation[$c] ?? null) ? $station : null, 'available' => 1,
                'show_online' => 0, 'show_web' => 0, 'show_qr' => 0, 'sort' => (int) $m['s'], 'active' => (int) $m['lck'] ? 0 : 1,
                'vat_rate' => null, 'legacy_id' => 'itk:item:' . $k, 'updated_at' => $this->now,
            ]);
        });
        $matched = count(array_filter($this->items, static fn(array $i): bool => !$i['own']));
        $this->report('categories', 'items');
        if ($matched) {
            $this->say("  $matched menu items matched to the existing menu by name");
        }
    }

    /** Category for items whose ItKafe group is missing. */
    private function spareCategory(): string
    {
        $id = self::uuid(self::EPOCH, 'itk:cat:0');
        $this->put('categories', ['id' => $id, 'names' => self::names('ItKafe'), 'station' => 'kitchen', 'vat_rate' => 0, 'sort' => 99990,
            'active' => 0, 'show_online' => 0, 'legacy_id' => 'itk:cat:0', 'updated_at' => $this->now]);
        return $id;
    }

    /**
     * S_Klient → customers and suppliers. Group 121 (Provider) and anyone used on a stock document is a supplier;
     * the other groups, and a provider with account moves or orders, are customers. Kl_Key 2 (the restaurant
     * itself) and 3 (walk-in guest) are left out.
     */
    private function people(): void
    {
        $sql = 'SELECT k.Kl_Key k, CAST(k.Kl_Name AS nvarchar(500)) name, k.Key_Tree g, CAST(k.K_Note AS nvarchar(2000)) note, CAST(k.Fl_Org AS int) org,
                CAST(k.K_INN AS nvarchar(100)) inn, CAST(k.K_Emale AS nvarchar(200)) email, CONVERT(varchar(10), k.K_DR, 126) dr, CAST(k.K_DR_FL AS int) drfl,
                CAST(k.K_Diskont AS nvarchar(100)) card, k.K_Skidka pct, CAST(k.K_FlBonus AS int) bonus, CAST(ROUND(-k.K_KreditNol * 100, 0) AS bigint) lim,
                CAST(k.F_Fl_Not_In_Find AS int) hidden, CONVERT(varchar(23), k.DataCreate, 126) created,
                CASE WHEN d.Kl_Key IS NULL THEN 0 ELSE 1 END docs, CASE WHEN o.Kl_Key IS NULL AND z.Kl_Key IS NULL THEN 0 ELSE 1 END used
            FROM S_Klient k
            LEFT JOIN (SELECT DISTINCT Kl_Key FROM S_Doc_Up) d ON d.Kl_Key = k.Kl_Key
            LEFT JOIN (SELECT DISTINCT Kl_Key FROM S_KlientOborot) o ON o.Kl_Key = k.Kl_Key
            LEFT JOIN (SELECT Kl_Key FROM R_Doc_Down UNION SELECT Kl_Key FROM R_Doc_DownDel) z ON z.Kl_Key = k.Kl_Key';
        $knownSup = [];
        foreach (Db::rows("SELECT id, name FROM suppliers WHERE deleted = 0 AND (legacy_id IS NULL OR legacy_id NOT LIKE 'itk:%')") as $s) {
            $knownSup[self::norm($s['name'])] ??= $s['id'];
        }
        $cols = ['k', 'name', 'g', 'note', 'org', 'inn', 'email', 'dr', 'drfl', 'card', 'pct', 'bonus', 'lim', 'hidden', 'created', 'docs', 'used'];
        $this->each($this->src->rows($sql, $cols), function (array $r) use ($knownSup): void {
            $k = (int) $r['k'];
            if ($k === self::OWN_COMPANY || $k === self::WALK_IN) {
                return;
            }
            $name = self::text($r['name']) ?? 'ItKafe #' . $k;
            $created = $this->ms($r['created']);
            $provider = (int) $r['g'] === self::SUPPLIER_GROUP;
            if ($provider || (int) $r['docs']) {
                $id = $knownSup[self::norm($name)] ?? self::uuid($created ?? self::EPOCH, 'itk:supplier:' . $k);
                $this->put('suppliers', ['id' => $id, 'name' => mb_substr($name, 0, 120), 'phone' => null, 'note' => self::text($r['note']),
                    'legacy_id' => 'itk:supplier:' . $k, 'updated_at' => $this->now]);
                $this->suppliers[$k] = $id;
            }
            if ($provider && !(int) $r['used']) {
                return;
            }
            [$phone, $card] = self::cardOrPhone($r['card']);
            $bonus = (bool) (int) $r['bonus'];
            $pct = round((float) $r['pct'], 2);
            $id = self::uuid($created ?? self::EPOCH, 'itk:customer:' . $k);
            $this->put('customers', [
                'id' => $id, 'name' => mb_substr($name, 0, 120), 'phone' => $phone, 'phone_norm' => $phone !== null ? (phone_norm($phone) ?: null) : null,
                'email' => self::text($r['email']), 'company' => (int) $r['org'] ? mb_substr($name, 0, 120) : null, 'tax_no' => self::text($r['inn']),
                'note' => self::join([self::text($r['note']), $card !== null ? 'ItKafe kart: ' . $card : null, $bonus && $pct > 0 ? 'ItKafe bonus %' . self::pct($pct) : null,
                    (int) $r['hidden'] ? 'ItKafe: aramada gizli' : null]),
                // K_Skidka is the customer's own discount only without bonus; with bonus it is the accrual % (kept in the note)
                'discount_pct' => $bonus ? 0 : $pct, 'loyalty' => $bonus ? 1 : 0,
                'credit_enabled' => (int) $r['lim'] > 0 ? 1 : 0, 'credit_limit' => max(0, (int) $r['lim']),
                'birthday' => (int) $r['drfl'] && $r['dr'] > '1900-01-01' ? $r['dr'] : null,
                'legacy_id' => 'itk:customer:' . $k, 'created_at' => $created, 'updated_at' => $this->now,
            ]);
            $this->customers[$k] = $id;
        });
        $this->report('customers', 'suppliers');
    }

    // ------------------------------------------------------------ orders

    /**
     * One order per idZakaz that has lines (the lines are the truth; headers without lines are skipped), then the
     * orders that exist only as deleted lines (void). Write-off tables become void orders booked as expenses.
     */
    private function orders(): void
    {
        $x = $this->fromDat;
        $ns = implode(',', array_keys($this->nonSales)) ?: '0';
        // payments on write-off tables stay out of the payments (as in ItKafe's reports); the note keeps them
        $nsPay = [];
        foreach ($this->src->rows("SELECT o.KeyTab z, o.VidKey v, CAST(ROUND(SUM(o.OplSum) * 100, 0) AS bigint) s FROM OplataDocument o
                WHERE o.OplSum <> 0 AND o.KeyTab IN (SELECT idZakaz FROM R_Doc_Down WHERE St_Key IN ($ns)) GROUP BY o.KeyTab, o.VidKey", ['z', 'v', 's']) as $r) {
            $nsPay[(int) $r['z']][] = self::methodLabel((int) $r['v']) . ' ' . self::tl((int) $r['s']);
        }
        // double closes: the same bill paid again under an earlier Close_Key; only the last close is imported (12 orders)
        $dupes = [];
        foreach ($this->src->rows('SELECT z, s FROM (SELECT o.KeyTab z, k.Close_Key c, CAST(ROUND(SUM(o.OplSum) * 100, 0) AS bigint) s,
                MAX(k.Close_Key) OVER (PARTITION BY o.KeyTab) mc FROM OplataDocument o JOIN ForKACloseOplata k ON k.KAOpl_Key = o.Oplkey
                WHERE o.OplSum <> 0 GROUP BY o.KeyTab, k.Close_Key) x WHERE c < mc', ['z', 's']) as $r) {
            $dupes[(int) $r['z']] = ($dupes[(int) $r['z']] ?? 0) + (int) $r['s'];
        }
        $sql = "SELECT L.z, L.dat, L.st, L.kl, L.waiter, CONVERT(varchar(23), L.first_line, 126) first_line, CONVERT(varchar(23), L.last_paid, 126) last_paid,
                L.prints, L.subtotal, L.discount, L.total, L.pmin, L.pmax, L.undisc, CONVERT(varchar(23), i.DataCreate, 126) info, i.DocNote note,
                CONVERT(varchar(23), c.closed, 126) closed, ISNULL(p.n, 0) pays
            FROM (SELECT idZakaz z, MIN(D_Dat) dat, MIN(St_Key) st, MIN(Kl_Key) kl, MIN(Key_JobCreate) waiter, MIN(R_Down_Create) first_line,
                    MAX(D_Down_Oplacheno) last_paid, MAX(Print_S4et) prints,
                    CAST(ROUND(SUM(D_Down_Kol * MyCena) * 100, 0) AS bigint) subtotal, CAST(ROUND(SUM(D_Down_Kol * MySkid) * 100, 0) AS bigint) discount,
                    CAST(ROUND(SUM(MySumma) * 100, 0) AS bigint) total,
                    MIN(CASE WHEN MySkid <> 0 THEN D_Down_Skid END) pmin, MAX(CASE WHEN MySkid <> 0 THEN D_Down_Skid END) pmax,
                    SUM(CASE WHEN MySkid = 0 AND MyCena <> 0 THEN 1 ELSE 0 END) undisc
                FROM R_Doc_Down GROUP BY idZakaz) L
            LEFT JOIN R_Doc_Info i ON i.idZakaz = L.z
            LEFT JOIN (SELECT IdZakaz, MAX(DataCreate) closed FROM ForKAClose GROUP BY IdZakaz) c ON c.IdZakaz = L.z
            LEFT JOIN (SELECT KeyTab, COUNT(*) n FROM OplataDocument WHERE OplSum <> 0 GROUP BY KeyTab) p ON p.KeyTab = L.z";
        $cols = ['z', 'dat', 'st', 'kl', 'waiter', 'first_line', 'last_paid', 'prints', 'subtotal', 'discount', 'total', 'pmin', 'pmax', 'undisc', 'info', 'note', 'closed', 'pays'];
        // "from" is applied here: with HAVING MIN(D_Dat) >= from SQL Server picks a plan that takes minutes
        $n = 0;
        $this->each($this->src->rows($sql, $cols), function (array $r) use ($nsPay, $dupes, $x, &$n): void {
            if ((int) $r['dat'] < $x) {
                return;
            }
            $n++;
            $z = (int) $r['z'];
            $st = (int) $r['st'];
            // the header is later than the first line on re-keyed (moved / split) orders
            $opened = self::least($this->ms($r['info']), $this->ms($r['first_line'])) ?? self::EPOCH;
            $closed = $this->ms($r['closed']);
            $day = self::day((int) $r['dat']) ?? $this->dayOf($opened);
            $note = [self::text($r['note'])];
            if (isset($this->nonSales[$st])) {
                // write-off tables (98 staff meals, 81 boss): not sales; their value goes to finance as an expense (nonSales())
                $status = 'void';
                $note[] = 'Satış dışı masa: ' . ($this->tableNames[$st] ?? $st) . ' (ItKafe)';
                if (isset($nsPay[$z])) {
                    $note[] = 'ödeme: ' . implode(', ', $nsPay[$z]);
                }
                $this->noPay[$z] = true;
                if ((int) $r['total'] !== 0) {
                    $this->spend[$st][$day][0] = ($this->spend[$st][$day][0] ?? 0) + 1;
                    $this->spend[$st][$day][1] = ($this->spend[$st][$day][1] ?? 0) + (int) $r['total'];
                }
            } elseif ((int) $r['pays'] > 0 || $closed !== null) {
                $status = 'paid';
                $closed ??= $this->ms($r['last_paid']);
            } else {
                // 166251: never paid nor closed; ItKafe still counts its 580 TL in October
                $status = 'void';
                $note[] = "ItKafe'de açık kaldı, ödenmedi";
            }
            if (isset($dupes[$z])) {
                $note[] = 'ItKafe: hesap iki kez kapatılmış, önceki kapama (' . self::tl($dupes[$z]) . ') aktarılmadı';
            }
            $id = $this->order($z, $day, $st, (int) $r['kl'], (int) $r['waiter'], $opened, $closed, $status, $note,
                (int) $r['subtotal'], (int) $r['discount'], (int) $r['total'], (int) $r['prints']);
            $disc = (int) $r['discount'];
            if ($disc !== 0) {
                // ItKafe discounts are per line; Sofrexa keeps one per order: a % when every priced line had the same one
                $pct = $r['pmin'] !== '' && (float) $r['pmin'] === (float) $r['pmax'] && (int) $r['undisc'] === 0 ? (float) $r['pmin'] : 0.0;
                $this->put('order_discounts', ['id' => self::uuid($closed ?? $opened, 'itk:disc:' . $z), 'order_id' => $id, 'kind' => $pct > 0 ? 'pct' : 'amount',
                    'value' => $pct > 0 ? $pct : $disc, 'amount' => $disc, 'reason' => 'ItKafe', 'user_id' => null, 'at' => $closed ?? $opened]);
            }
        });
        $sql = "SELECT d.idZakaz z, MIN(d.D_Dat) dat, MIN(d.St_Key) st, MIN(d.Kl_Key) kl, MIN(d.Key_JobCreate) waiter,
                CONVERT(varchar(23), MIN(d.R_Down_Create), 126) first_line, CONVERT(varchar(23), MAX(d.RDownCreateTmp), 126) voided,
                CONVERT(varchar(23), MIN(i.DataCreate), 126) info, MAX(i.DocNote) note
            FROM R_Doc_DownDel d LEFT JOIN R_Doc_Info i ON i.idZakaz = d.idZakaz
            WHERE NOT EXISTS (SELECT 1 FROM R_Doc_Down x WHERE x.idZakaz = d.idZakaz)
            GROUP BY d.idZakaz";
        // the spec's two "payments without an order" (KeyTab 149633, 163287) belong to such orders: kept in the note only
        $voidPay = [];
        foreach ($this->src->rows('SELECT o.KeyTab z, o.VidKey v, CAST(ROUND(SUM(o.OplSum) * 100, 0) AS bigint) s FROM OplataDocument o
                WHERE o.OplSum <> 0 AND NOT EXISTS (SELECT 1 FROM R_Doc_Down x WHERE x.idZakaz = o.KeyTab) GROUP BY o.KeyTab, o.VidKey', ['z', 'v', 's']) as $r) {
            $voidPay[(int) $r['z']][] = self::methodLabel((int) $r['v']) . ' ' . self::tl((int) $r['s']);
        }
        $v = 0;
        $this->each($this->src->rows($sql, ['z', 'dat', 'st', 'kl', 'waiter', 'first_line', 'voided', 'info', 'note']), function (array $r) use ($voidPay, $x, &$v): void {
            if ((int) $r['dat'] < $x) {
                return;
            }
            $v++;
            $z = (int) $r['z'];
            $opened = self::least($this->ms($r['info']), $this->ms($r['first_line'])) ?? self::EPOCH;
            $this->order($z, self::day((int) $r['dat']) ?? $this->dayOf($opened), (int) $r['st'], (int) $r['kl'], (int) $r['waiter'], $opened,
                $this->ms($r['voided']), 'void', [self::text($r['note']), 'ItKafe: silinen adisyon',
                    isset($voidPay[$z]) ? 'ödenmiş, aktarılmadı: ' . implode(', ', $voidPay[$z]) : null], 0, 0, 0, 0);
            $this->noPay[$z] = true;
        });
        $this->say(sprintf('orders: %d with lines, %d void (only deleted lines)', $n, $v));
        $this->report('orders', 'order_discounts');
    }

    private function order(int $z, string $day, int $st, int $kl, int $waiter, int $opened, ?int $closed, string $status, array $note,
        int $subtotal, int $discount, int $total, int $prints): string
    {
        $channel = in_array($kl, self::DELIVERY, true) ? 'delivery' : (in_array($st, self::TAKEAWAY, true) ? 'takeaway' : 'table');
        $id = self::uuid($opened, 'itk:order:' . $z);
        $this->put('orders', [
            'id' => $id, 'no' => 0, 'day' => $day, 'channel' => $channel, 'status' => $status,
            'table_id' => $channel === 'table' ? ($this->tables[$st] ?? null) : null,
            'customer_id' => $this->customers[$kl] ?? null, 'waiter_id' => $this->staff[$waiter] ?? null,
            'guests' => 0, // KolMan/KolWoman/KolChildren are 1/0/0 on every order: not recorded
            'opened_at' => $opened, 'closed_at' => $closed, 'note' => self::join($note),
            'label' => $channel === 'takeaway' ? ($this->tableNames[$st] ?? null) : null,
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'paid' => 0, 'printed_bill' => max(0, $prints),
            'legacy_id' => 'itk:order:' . $z, 'updated_at' => $this->now,
        ]);
        $this->orders[$z] = $id;
        return $id;
    }

    /** Order lines (R_Doc_Down) and voided lines (R_Doc_DownDel). */
    private function lines(): void
    {
        $x = $this->fromDat;
        // every line of an imported order has D_Dat >= the order's MIN(D_Dat) >= from; lines of older orders are skipped by the order map
        $where = $x ? " WHERE d.D_Dat >= $x" : '';
        // round = the n-th time lines of the order were sent (D_Down_Sdelano); there was no kitchen screen
        $sql = 'SELECT d.R_Down_Key k, d.idZakaz z, d.Mn_Key m, d.D_Down_Kol q, CAST(ROUND(d.MyCena * 100, 0) AS bigint) price, d.D_Down_Note note,
                CAST(d.Podarok AS int) gift, CONVERT(varchar(23), d.R_Down_Create, 126) created, d.Key_JobCreate by_, CONVERT(varchar(23), d.D_Down_Sdelano, 126) sent,
                CONVERT(varchar(23), d.D_Down_Vidano, 126) served, DENSE_RANK() OVER (PARTITION BY d.idZakaz ORDER BY d.D_Down_Sdelano) rnd,
                \'\' voided, 0 vby
            FROM R_Doc_Down d' . $where;
        $cols = ['k', 'z', 'm', 'q', 'price', 'note', 'gift', 'created', 'by_', 'sent', 'served', 'rnd', 'voided', 'vby'];
        $this->each($this->src->rows($sql, $cols), fn(array $r) => $this->line($r, 'itk:line:' . $r['k'], false));
        // voided lines: no reason was recorded; typing-error outliers (qty 1,000,000 in 2022) are kept as they are
        $since = $x ? ' WHERE d.D_Dat >= ' . (int) (new \DateTimeImmutable((string) self::day($x)))->modify('-7 days')->format('Ymd') : '';
        $sql = 'SELECT d.RDownKeyTmp k, d.idZakaz z, d.Mn_Key m, d.D_Down_Kol q, CAST(ROUND(d.MyCena * 100, 0) AS bigint) price, d.D_Down_Note note,
                CAST(d.Podarok AS int) gift, CONVERT(varchar(23), d.R_Down_Create, 126) created, d.Key_JobCreate by_,
                CASE WHEN d.Fl_Sdelano = 1 THEN CONVERT(varchar(23), d.D_Down_Sdelano, 126) END sent,
                CASE WHEN d.Fl_Vidano = 1 THEN CONVERT(varchar(23), d.D_Down_Vidano, 126) END served, 1 rnd,
                CONVERT(varchar(23), d.RDownCreateTmp, 126) voided, d.RDownKeyDelTmp vby
            FROM R_Doc_DownDel d' . $since;
        $this->each($this->src->rows($sql, $cols), fn(array $r) => $this->line($r, 'itk:vline:' . $r['k'], true));
        $void = (int) Db::value("SELECT COUNT(*) FROM order_items WHERE status = 'void'");
        $this->say(sprintf('order lines: %d added (%d void in the file)', $this->added['order_items'] ?? 0, $void));
    }

    private function line(array $r, string $key, bool $void): void
    {
        $oid = $this->orders[(int) $r['z']] ?? null;
        if ($oid === null) {
            return;
        }
        $m = (int) $r['m'];
        $item = $this->items[$m] ?? null; // 38 voided lines point at deleted items: no item
        $created = $this->ms($r['created']) ?? self::EPOCH;
        $this->put('order_items', [
            'id' => self::uuid($created, $key), 'order_id' => $oid, 'item_id' => $item['id'] ?? null, 'name' => $item['name'] ?? 'ItKafe #' . $m,
            'qty' => (float) $r['q'], 'unit_price' => (int) $r['price'], 'mods' => '[]', 'mods_price' => 0,
            'note' => self::join([(int) $r['gift'] ? 'ikram' : null, self::text($r['note'])]),
            'station' => $item['station'] ?? 'kitchen', 'round' => max(1, (int) $r['rnd']), 'status' => $void ? 'void' : 'served',
            'vat_rate' => 0, 'cost' => 0, 'created_by' => $this->staff[(int) $r['by_']] ?? null, 'created_at' => $created,
            'sent_at' => $this->ms($r['sent']), 'served_at' => $this->ms($r['served']),
            'void_reason' => null, 'void_by' => $void ? ($this->staff[(int) $r['vby']] ?? null) : null, 'void_at' => $void ? $this->ms($r['voided']) : null,
            'updated_at' => $this->now,
        ]);
    }

    /** OplataDocument rows of each order's last close (earlier closes are double closes), amount ≠ 0. */
    private function payments(): void
    {
        $sql = "SELECT o.Oplkey k, o.KeyTab z, o.VidKey v, CAST(ROUND(o.OplSum * 100, 0) AS bigint) amount, o.Kl_Key kl, k.Close_Key c,
                MAX(k.Close_Key) OVER (PARTITION BY o.KeyTab) mc, CONVERT(varchar(23), f.DataCreate, 126) at, p.v cashier
            FROM OplataDocument o JOIN ForKACloseOplata k ON k.KAOpl_Key = o.Oplkey JOIN ForKAClose f ON f.Close_Key = k.Close_Key
            LEFT JOIN (SELECT Close_Key, MAX(MyValue) v FROM ForKACloseParam WHERE MyKey = 'No_PeopleCreate' GROUP BY Close_Key) p ON p.Close_Key = f.Close_Key
            WHERE o.OplSum <> 0";
        $skipped = 0;
        $dupes = 0;
        $this->each($this->src->rows($sql, ['k', 'z', 'v', 'amount', 'kl', 'c', 'mc', 'at', 'cashier']), function (array $r) use (&$skipped, &$dupes): void {
            $z = (int) $r['z'];
            if (!isset($this->orders[$z]) || isset($this->noPay[$z])) {
                $skipped++;
                return;
            }
            if ((int) $r['c'] < (int) $r['mc']) {
                $dupes++;
                return;
            }
            $v = (int) $r['v'];
            $at = $this->ms($r['at']) ?? self::EPOCH;
            $this->put('payments', [
                'id' => self::uuid($at, 'itk:pay:' . $r['k']), 'order_id' => $this->orders[$z], 'shift_id' => null, 'method' => self::METHOD[$v] ?? 'cash',
                'currency' => 'TRY', 'amount_fx' => 0, 'rate' => 1, 'amount' => (int) $r['amount'], 'change_given' => 0,
                'customer_id' => $this->customers[(int) $r['kl']] ?? null, 'at' => $at, 'user_id' => $this->staff[(int) $r['cashier']] ?? null,
                'note' => isset(self::FX[$v]) ? 'ItKafe: ' . self::FX[$v] . ', kur kaydı yok' : null,
            ]);
        });
        Db::tx(static function (): void {
            Db::exec("UPDATE orders SET paid = COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = orders.id), 0),
                closed_at = COALESCE(closed_at, (SELECT MAX(p.at) FROM payments p WHERE p.order_id = orders.id))
                WHERE legacy_id LIKE 'itk:order:%' AND status = 'paid'");
        });
        $this->say(sprintf('payments: %d added; left out: %d of earlier (double) closes, %d of write-off tables, deleted or not imported orders',
            $this->added['payments'] ?? 0, $dupes, $skipped));
    }

    /**
     * Write-off tables are not sales: what was served there is booked as an expense per business day and table
     * (menu prices, as ItKafe charged them; staff meals 98 → category staff, 81 → other). No money moved: method "account".
     */
    private function nonSales(): void
    {
        $rows = [];
        foreach ($this->spend as $st => $days) {
            foreach ($days as $day => [$n, $sum]) {
                $rows[] = [$st, $day, $n, $sum];
            }
        }
        $this->each($rows, function (array $r): void {
            [$st, $day, $n, $sum] = $r;
            $at = (new \DateTimeImmutable($day . ' 12:00:00', $this->tz))->getTimestamp() * 1000;
            $this->put('finance_entries', [
                'id' => self::uuid($at, 'itk:fin:' . $st . ':' . $day), 'kind' => 'expense', 'category' => $this->nonSales[$st],
                'description' => 'ItKafe masa ' . ($this->tableNames[$st] ?? $st) . ' · ' . $n . ' adisyon (menü fiyatıyla)', 'day' => $day, 'amount' => $sum,
                'currency' => 'TRY', 'amount_fx' => 0, 'method' => 'account', 'source' => 'itkafe', 'ref_id' => null, 'user_id' => null, 'at' => $at,
            ]);
        });
        $this->report('finance_entries');
    }

    // ------------------------------------------------------------ money

    /**
     * S_KlientOborot → account_ledger, as ItKafe shows it (one balance mixing debt and bonus; the doubled −900 of
     * order 104165 included). Sofrexa's sign is the other way round: positive = the customer owes.
     * Charges and bonus rows are tied to their order (same customer and amount, closed within 5 s).
     * Bonus: ItKafe has no separate wallet. For bonus customers without credit, a positive balance is bonus: it moves
     * to loyalty points (1 point = loyalty.point_value, rounded to whole points) and their account goes to 0. Everyone
     * else keeps it as the account balance.
     */
    private function ledger(): void
    {
        $sql = 'SELECT k.Key_Oborot k, k.Kl_Key kl, CAST(ROUND(k.SumObor * 100, 0) AS bigint) amount, k.Razdel r, k.NoteObor note,
                CONVERT(varchar(23), k.DataObor, 126) at, k.Key_JobCreate by_, m.z
            FROM S_KlientOborot k LEFT JOIN (
                SELECT x.id, x.z, ROW_NUMBER() OVER (PARTITION BY x.id ORDER BY x.gap, x.z DESC) rn FROM (
                    SELECT k2.Key_Oborot id, o.KeyTab z, ABS(DATEDIFF(second, f.DataCreate, k2.DataCreate)) gap
                    FROM S_KlientOborot k2 JOIN OplataDocument o ON o.Kl_Key = k2.Kl_Key AND o.OplSum = -k2.SumObor AND o.VidKey = 3
                    JOIN ForKACloseOplata c ON c.KAOpl_Key = o.Oplkey JOIN ForKAClose f ON f.Close_Key = c.Close_Key
                    WHERE k2.Razdel = 1 AND k2.SumObor < 0 AND ABS(DATEDIFF(second, f.DataCreate, k2.DataCreate)) <= 5
                    UNION ALL
                    SELECT k2.Key_Oborot, b.z, ABS(DATEDIFF(second, f.DataCreate, k2.DataCreate))
                    FROM S_KlientOborot k2 JOIN (SELECT idZakaz z, MIN(Kl_Key) kl, SUM(MyBonus) b FROM R_Doc_Down GROUP BY idZakaz HAVING SUM(MyBonus) <> 0) b
                        ON b.kl = k2.Kl_Key AND b.b = k2.SumObor
                    JOIN ForKAClose f ON f.IdZakaz = b.z
                    WHERE k2.Razdel = 1 AND k2.SumObor > 0 AND ABS(DATEDIFF(second, f.DataCreate, k2.DataCreate)) <= 5
                ) x
            ) m ON m.id = k.Key_Oborot AND m.rn = 1';
        $opening = [];
        $linked = 0;
        $this->each($this->src->rows($sql, ['k', 'kl', 'amount', 'r', 'note', 'at', 'by_', 'z']), function (array $r) use (&$opening, &$linked): void {
            $cid = $this->customers[(int) $r['kl']] ?? null;
            if ($cid === null) {
                return;
            }
            $amount = -(int) $r['amount'];
            $at = $this->ms($r['at']) ?? self::EPOCH;
            if ($at < $this->fromMs) {
                $opening[$cid] = ($opening[$cid] ?? 0) + $amount;
                return;
            }
            $note = self::text($r['note']);
            $kind = self::ledgerKind((int) $r['r'], (int) $r['amount']);
            $order = $r['z'] !== '' ? ($this->orders[(int) $r['z']] ?? null) : null;
            $linked += (int) ($order !== null);
            $this->put('account_ledger', [
                'id' => self::uuid($at, 'itk:acc:' . $r['k']), 'customer_id' => $cid, 'amount' => $amount, 'kind' => $kind, 'order_id' => $order,
                'method' => $kind === 'payment' ? self::ledgerMethod((string) $note) : null,
                'note' => $kind === 'bonus' ? ($note ?? 'ItKafe bonus') : $note, 'at' => $at, 'user_id' => $this->staff[(int) $r['by_']] ?? null,
            ]);
        });
        foreach ($opening as $cid => $sum) {
            if ($sum !== 0) {
                $this->put('account_ledger', ['id' => self::uuid($this->fromMs - 1, 'itk:acc:open:' . $cid), 'customer_id' => $cid, 'amount' => $sum, 'kind' => 'opening',
                    'order_id' => null, 'method' => null, 'note' => 'ItKafe', 'at' => $this->fromMs - 1, 'user_id' => null]);
            }
        }
        // bonus-only customers (bonus on, no credit) with money in their favour: the balance becomes points
        $pv = max(1, (int) Settings::get('loyalty.point_value', 100));
        $bonus = Db::rows("SELECT a.customer_id AS id, SUM(a.amount) AS b FROM account_ledger a JOIN customers c ON c.id = a.customer_id
            WHERE c.legacy_id LIKE 'itk:%' AND c.loyalty = 1 AND c.credit_enabled = 0 GROUP BY a.customer_id HAVING SUM(a.amount) < 0");
        $moved = 0;
        $this->each($bonus, function (array $r) use ($pv, &$moved): void {
            $points = (int) round(-(int) $r['b'] / $pv);
            if ($points <= 0) {
                return;
            }
            $this->put('account_ledger', ['id' => self::uuid($this->now, 'itk:bonus:acc:' . $r['id']), 'customer_id' => $r['id'], 'amount' => -(int) $r['b'],
                'kind' => 'adjust', 'order_id' => null, 'method' => null, 'note' => 'ItKafe bonus → puan', 'at' => $this->now, 'user_id' => null]);
            $this->put('loyalty_ledger', ['id' => self::uuid($this->now, 'itk:bonus:pts:' . $r['id']), 'customer_id' => $r['id'], 'points' => $points,
                'kind' => 'import', 'order_id' => null, 'note' => 'ItKafe bonus ' . self::tl(-(int) $r['b']), 'at' => $this->now, 'user_id' => null]);
            $moved += -(int) $r['b'];
        });
        $this->say(sprintf('account ledger: %d added (%d tied to their order); bonus → points: %d customers, %s TL',
            $this->added['account_ledger'] ?? 0, $linked, $this->added['loyalty_ledger'] ?? 0, self::tl($moved)));
    }

    /** Waiter-till cash in / out (R_Kassa_Of Vid_Opl = 0; 1 and 2 are automatic card / account offsets of the payments). */
    private function cash(): void
    {
        $sql = 'SELECT RKas_Key k, CAST(ROUND(RKas_SumP * 100, 0) AS bigint) p, CAST(ROUND(RKas_SumR * 100, 0) AS bigint) r, Note note, Key_Job by_,
                CONVERT(varchar(23), RKas_Data_Create, 126) at FROM R_Kassa_Of WHERE Vid_Opl = 0 AND Dat >= ' . $this->fromDat;
        $this->each($this->src->rows($sql, ['k', 'p', 'r', 'note', 'by_', 'at']), function (array $r): void {
            $in = (int) $r['p'] > 0;
            $note = self::text($r['note']) ?? 'ItKafe';
            $at = $this->ms($r['at']) ?? self::EPOCH;
            $this->put('cash_moves', [
                'id' => self::uuid($at, 'itk:cash:' . $r['k']), 'shift_id' => null, 'kind' => $in ? 'in' : 'out', 'currency' => 'TRY', 'amount_fx' => 0,
                'amount' => $in ? (int) $r['p'] : -abs((int) $r['r']), 'reason' => mb_substr($note, 0, 120), 'note' => mb_strlen($note) > 120 ? $note : null,
                'photo' => null, 'reverses' => null, 'ref' => null, 'user_id' => $this->staff[(int) $r['by_']] ?? null, 'at' => $at,
            ]);
        });
        $this->report('cash_moves');
    }

    // ------------------------------------------------------------ stock

    /**
     * Stock items, purchase / return / write-off documents with their lines, and one closing move per item so that
     * the quantity on hand equals ItKafe's (S_Skl_Ass: sales consumption is not in the documents).
     */
    private function stock(): void
    {
        // units are not stored in ItKafe: "adet" when every quantity of the item is whole, else "kg" (to be checked)
        $fractional = [];
        foreach ($this->src->rows('SELECT a FROM (SELECT A_Key a, CAST(KolA_Key AS decimal(18, 4)) q FROM R_MenuCurCalc UNION ALL SELECT A_Key, CAST(DD_Kol AS decimal(18, 4)) FROM S_Doc_Down
                UNION ALL SELECT A_Key, CAST(AS_Ost AS decimal(18, 4)) FROM S_Skl_Ass) x GROUP BY a HAVING SUM(CASE WHEN q <> ROUND(q, 0) THEN 1 ELSE 0 END) > 0', ['a']) as $r) {
            $fractional[(int) $r['a']] = true;
        }
        $lastSupplier = [];
        foreach ($this->src->rows('SELECT a, kl FROM (SELECT d.A_Key a, u.Kl_Key kl, ROW_NUMBER() OVER (PARTITION BY d.A_Key ORDER BY u.DU_Dat1 DESC, u.DU_Key DESC) rn
                FROM S_Doc_Down d JOIN S_Doc_Up u ON u.DU_Key = d.DU_Key WHERE u.Key_Type = 1) x WHERE rn = 1', ['a', 'kl']) as $r) {
            $lastSupplier[(int) $r['a']] = (int) $r['kl'];
        }
        $known = [];
        foreach (Db::rows("SELECT id, name FROM stock_items WHERE deleted = 0 AND (legacy_id IS NULL OR legacy_id NOT LIKE 'itk:%')") as $s) {
            $known[self::norm($s['name'])] ??= $s['id'];
        }
        $sql = 'SELECT a.A_Key k, CAST(a.A_Name AS nvarchar(500)) name, g.Tree_Name grp, a.A_PriceSebMenu cost, s.q, a.MinOst mn, CONVERT(varchar(23), a.DataCreate, 126) created
            FROM S_Assort a LEFT JOIN S_TreeView g ON g.Key_Tree = a.Key_Tree LEFT JOIN (SELECT A_Key, SUM(AS_Ost) q FROM S_Skl_Ass GROUP BY A_Key) s ON s.A_Key = a.A_Key';
        $this->each($this->src->rows($sql, ['k', 'name', 'grp', 'cost', 'q', 'mn', 'created']), function (array $r) use ($fractional, $lastSupplier, $known): void {
            $k = (int) $r['k'];
            $name = self::text($r['name']) ?? 'ItKafe #' . $k;
            $id = $known[self::norm($name)] ?? self::uuid($this->ms($r['created']) ?? self::EPOCH, 'itk:stock:' . $k);
            $cost = round((float) $r['cost'] * 100, 2); // unit costs have 4 decimals: kept as a decimal kuruş amount
            $this->put('stock_items', [
                'id' => $id, 'name' => mb_substr($name, 0, 120), 'unit' => isset($fractional[$k]) ? 'kg' : 'adet', 'category' => self::text($r['grp']),
                'kind' => 'raw', 'min_qty' => max(0.0, (float) $r['mn']), 'avg_cost' => $cost, 'supplier_id' => $this->suppliers[$lastSupplier[$k] ?? 0] ?? null,
                'legacy_id' => 'itk:stock:' . $k, 'active' => 1, 'vat_rate' => 0, 'updated_at' => $this->now,
            ]);
            $this->stock[$k] = ['id' => $id, 'cost' => $cost, 'qty' => (float) $r['q']];
        });
        $kinds = [1 => 'purchase', 11 => 'return', 12 => 'waste'];
        $since = $this->fromDat ? " AND u.DU_Dat1 >= CONVERT(datetime, '" . $this->fromDat . "', 112)" : '';
        $docs = [];
        $sql = 'SELECT u.DU_Key k, u.Key_Type t, u.Kl_Key kl, u.DU_Nomer1 no, CONVERT(varchar(10), u.DU_Dat1, 126) day, CONVERT(varchar(23), u.Data_Create, 126) created,
                u.Key_Job by_, CAST(u.DU_Primech AS nvarchar(1000)) note, CAST(ROUND(u.DU_Sum * 100, 0) AS bigint) total, CAST(ROUND(u.DU_Oplata * 100, 0) AS bigint) paid
            FROM S_Doc_Up u WHERE u.Key_Type IN (1, 11, 12)' . $since;
        $this->each($this->src->rows($sql, ['k', 't', 'kl', 'no', 'day', 'created', 'by_', 'note', 'total', 'paid']), function (array $r) use ($kinds, &$docs): void {
            $kind = $kinds[(int) $r['t']];
            $at = $this->ms($r['created']) ?? $this->ms($r['day'] . 'T12:00:00') ?? self::EPOCH;
            $note = self::text($r['note']);
            $total = (int) $r['total'];
            $paid = (int) $r['paid'];
            $id = self::uuid($at, 'itk:sdoc:' . $r['k']);
            $this->put('stock_docs', [
                'id' => $id, 'kind' => $kind, 'supplier_id' => $this->suppliers[(int) $r['kl']] ?? null, 'doc_no' => (int) $r['no'] ? (string) (int) $r['no'] : null,
                'day' => $r['day'], 'total' => $total, 'vat' => 0, 'pay_method' => null,
                'note' => self::join([$note, $kind === 'purchase' && $paid < $total ? 'ItKafe: ödenen ' . self::tl($paid) . ' / ' . self::tl($total) : null]),
                'user_id' => $this->staff[(int) $r['by_']] ?? null, 'at' => $at, 'legacy_id' => 'itk:sdoc:' . $r['k'],
            ]);
            $docs[(int) $r['k']] = [$id, $kind, $at, $this->staff[(int) $r['by_']] ?? null, $kind === 'waste' && $note !== null ? 'waste:' . mb_substr($note, 0, 60) : $kind];
        });
        $sql = 'SELECT d.DD_Key k, d.DU_Key doc, d.A_Key a, d.DD_Kol q, d.DD_CenSNDS price FROM S_Doc_Down d JOIN S_Doc_Up u ON u.DU_Key = d.DU_Key
            WHERE u.Key_Type IN (1, 11, 12)' . $since;
        $this->each($this->src->rows($sql, ['k', 'doc', 'a', 'q', 'price']), function (array $r) use ($docs): void {
            $doc = $docs[(int) $r['doc']] ?? null;
            $item = $this->stock[(int) $r['a']] ?? null;
            if ($doc === null || $item === null) {
                return;
            }
            [$docId, $kind, $at, $user, $reason] = $doc;
            $qty = (float) $r['q'];
            $this->put('stock_moves', ['id' => self::uuid($at, 'itk:smove:' . $r['k']), 'doc_id' => $docId, 'stock_item_id' => $item['id'],
                'qty' => $kind === 'purchase' ? $qty : -$qty, 'unit_cost' => round((float) $r['price'] * 100, 2), 'reason' => $reason,
                'order_item_id' => null, 'at' => $at, 'user_id' => $user]);
        });
        $onHand = Db::pairs('SELECT stock_item_id, SUM(qty) FROM stock_moves GROUP BY stock_item_id');
        $end = $this->endMs();
        $closing = 0;
        $this->each($this->stock, function (array $s, int $k) use ($onHand, $end, &$closing): void {
            $diff = round($s['qty'] - (float) ($onHand[$s['id']] ?? 0), 4);
            if (abs($diff) >= 0.0005) {
                $closing += (int) $this->put('stock_moves', ['id' => self::uuid($end, 'itk:stock:close:' . $k), 'doc_id' => null, 'stock_item_id' => $s['id'],
                    'qty' => $diff, 'unit_cost' => $s['cost'], 'reason' => 'count:ItKafe devir', 'order_item_id' => null, 'at' => $end, 'user_id' => null]);
            }
        });
        $this->report('stock_items', 'stock_docs', 'stock_moves');
        $this->say("  $closing closing moves set the quantity on hand to ItKafe's");
    }

    /** R_MenuCurCalc: the flattened recipe of each menu item (per portion), and the item cost it gives. */
    private function recipes(): void
    {
        $has = Db::pairs("SELECT DISTINCT r.parent_id, 1 FROM recipes r JOIN items i ON i.id = r.parent_id
            WHERE r.parent_kind = 'item' AND r.deleted = 0 AND (i.legacy_id IS NULL OR i.legacy_id NOT LIKE 'itk:%')");
        // several ItKafe items can match one existing item: its recipe comes from the active, newest of them
        $owner = [];
        foreach ($this->items as $m => $i) {
            $cur = $owner[$i['id']] ?? null;
            if ($cur === null || [$i['active'], $m] > [$this->items[$cur]['active'], $cur]) {
                $owner[$i['id']] = $m;
            }
        }
        $cost = [];
        $this->each($this->src->rows('SELECT Mn_Key m, A_Key a, KolA_Key q, SebA_Key c FROM R_MenuCurCalc WHERE KolA_Key > 0', ['m', 'a', 'q', 'c']),
            function (array $r) use ($has, $owner, &$cost): void {
                $m = (int) $r['m'];
                $item = $this->items[$m] ?? null;
                $stock = $this->stock[(int) $r['a']] ?? null;
                if ($item === null || $stock === null || isset($has[$item['id']]) || $owner[$item['id']] !== $m) {
                    return;
                }
                $this->put('recipes', ['id' => self::uuid(self::EPOCH, 'itk:recipe:' . $m . ':' . $r['a']), 'parent_kind' => 'item', 'parent_id' => $item['id'],
                    'stock_item_id' => $stock['id'], 'qty' => round((float) $r['q'], 6), 'waste_pct' => 0, 'updated_at' => $this->now]);
                // SebA_Key is the stock item's unit cost
                $cost[$item['id']] = ($cost[$item['id']] ?? 0.0) + (float) $r['q'] * (float) $r['c'] * 100;
            });
        $this->each(array_keys($cost), function (string $id) use ($cost): void {
            Db::exec('UPDATE items SET cost = ? WHERE id = ?', [(int) round($cost[$id]), $id]);
        });
        $this->report('recipes');
    }

    // ------------------------------------------------------------ staff

    /**
     * Z_Smena_History → clock in / out, from January of the first order's year (the 8 test rows of 2012 stay out; the 44 shifts
     * before the opening day are kept) to the last business day (the 2 open rows of the backup day stay out).
     */
    private function attendance(): void
    {
        $sql = 'SELECT Key_Hist k, Key_Job j, CONVERT(varchar(23), Dat_Start, 126) s, CONVERT(varchar(23), Dat_End, 126) e FROM Z_Smena_History
            WHERE Dat >= ' . max($this->fromDat, intdiv($this->firstDat, 10000) * 10000 + 101) . ' AND Dat <= ' . $this->lastDat;
        $broken = 0;
        $this->each($this->src->rows($sql, ['k', 'j', 's', 'e']), function (array $r) use (&$broken): void {
            $uid = $this->staff[(int) $r['j']] ?? null;
            $in = $this->ms($r['s']);
            if ($uid === null || $in === null) {
                return;
            }
            $out = $this->ms($r['e']);
            // end before start, no end, or over 20 h (a forgotten clock-out): kept as a zero-length shift flagged in
            // "device", so the day shows as worked without a made-up duration
            $ok = $out !== null && $out >= $in && $out - $in <= self::MAX_SHIFT_MS;
            $broken += (int) !$ok;
            $device = $ok ? 'itkafe' : 'itkafe:broken';
            $this->put('time_entries', ['id' => self::uuid($in, 'itk:shift:in:' . $r['k']), 'user_id' => $uid, 'kind' => 'in', 'at' => $in, 'device' => $device]);
            $this->put('time_entries', ['id' => self::uuid($ok ? $out : $in, 'itk:shift:out:' . $r['k']), 'user_id' => $uid, 'kind' => 'out', 'at' => $ok ? $out : $in, 'device' => $device]);
        });
        $this->report('time_entries');
        $this->say("  $broken broken shifts kept with no duration (device itkafe:broken)");
    }

    /** Z_Viplata_History → payroll (see payrollRow()); zero rows and the typo HV_Key 35104 are left out. */
    private function payroll(): void
    {
        $sql = 'SELECT HV_Key k, No_People p, CONVERT(varchar(23), HV_Data, 126) at, HV_Dat dat, CAST(ROUND(HV_Summa * 100, 0) AS bigint) amount, HV_Note note, HV_Dop d
            FROM Z_Viplata_History WHERE HV_Summa <> 0 AND HV_Key <> ' . self::PAYROLL_TYPO . ' AND HV_Dat >= ' . $this->fromDat;
        $this->each($this->src->rows($sql, ['k', 'p', 'at', 'dat', 'amount', 'note', 'd']), function (array $r): void {
            $uid = $this->staff[(int) $r['p']] ?? null;
            if ($uid === null) {
                return;
            }
            $at = $this->ms($r['at']) ?? self::EPOCH;
            $note = self::text($r['note']);
            $this->put('payroll', ['id' => self::uuid($at, 'itk:payroll:' . $r['k']), 'user_id' => $uid,
                'period' => substr((string) (self::day((int) $r['dat']) ?? date('Y-m-d', intdiv($at, 1000))), 0, 7)]
                + self::payrollRow((int) $r['d'], (int) $r['amount'], (string) $note)
                + ['note' => $note, 'at' => $at, 'user_by' => null]);
        });
        $this->report('payroll');
    }

    /**
     * ItKafe payroll row → Sofrexa payroll columns. Sofrexa counts "total" as paid to the person, so:
     * HV_Dop 1 (accrual per shift, "Robot") → kind accrual, base = amount, total 0;
     * HV_Dop 2 negative → a payout (advance when the note says so), or a penalty (kind accrual, deduction);
     * HV_Dop 2 positive → a correction of the accrual (bonus); HV_Dop 3 → payout through the cash desk (cash).
     */
    public static function payrollRow(int $dop, int $amount, string $note): array
    {
        $row = ['kind' => 'accrual', 'base' => 0, 'commission' => 0, 'bonus' => 0, 'deduction' => 0, 'total' => 0, 'method' => null];
        if ($dop === 1) {
            return ['base' => $amount] + $row;
        }
        if ($amount > 0) {
            return ['bonus' => $amount] + $row;
        }
        $n = mb_strtolower($note, 'UTF-8');
        if ($dop === 2 && preg_match('/pinalty|penalty|ceza|штраф/u', $n)) {
            return ['deduction' => -$amount] + $row;
        }
        return ['kind' => preg_match('/avans|advance|аванс/u', $n) ? 'advance' : 'payment', 'total' => -$amount, 'method' => $dop === 3 ? 'cash' : null] + $row;
    }

    /** Orders are numbered per business day in the order they were opened. */
    private function numbers(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DROP TABLE IF EXISTS temp.itk_no');
        $pdo->exec("CREATE TEMP TABLE itk_no AS SELECT id, ROW_NUMBER() OVER (PARTITION BY day ORDER BY opened_at, CAST(substr(legacy_id, 11) AS INTEGER)) AS n
            FROM orders WHERE legacy_id LIKE 'itk:order:%'");
        $pdo->exec('CREATE UNIQUE INDEX temp.itk_no_id ON itk_no(id)');
        Db::tx(static fn() => Db::exec("UPDATE orders SET no = (SELECT n FROM itk_no WHERE itk_no.id = orders.id) WHERE legacy_id LIKE 'itk:order:%'"));
        $pdo->exec('DROP TABLE temp.itk_no');
    }

    // ------------------------------------------------------------ control totals

    /**
     * Control totals of the imported file against ItKafe: sales and paid orders per business month (ItKafe's
     * Stat_DayOplata and the spec's recount), payments per method, customer balances and stock on hand. Numbers only.
     */
    public function check(): void
    {
        $range = Db::row("SELECT MIN(day) AS a, MAX(day) AS b, COUNT(*) AS n FROM orders WHERE legacy_id LIKE 'itk:order:%'");
        if (!(int) $range['n']) {
            $this->say('This database holds no ItKafe orders.');
            return;
        }
        $a = (int) str_replace('-', '', (string) $range['a']);
        $b = (int) str_replace('-', '', (string) $range['b']);
        $ym = static fn(string $d): string => substr($d, 0, 4) . '-' . substr($d, 4, 2);
        $m = [];
        foreach ($this->src->rows("SELECT dayId / 100 ym, CAST(ROUND(SUM(SumKOplata) * 100, 0) AS bigint) s FROM Stat_DayOplata
                WHERE VidKey = 0 AND NoPeople = 0 AND dayId BETWEEN $a AND $b GROUP BY dayId / 100", ['ym', 's']) as $r) {
            $m[$ym($r['ym'])]['itk'] = (int) $r['s'];
        }
        // the spec's recount from the lines: sales tables only, per order by MIN(D_Dat); paid = at least one payment row
        // (joined in PHP: for the joined query SQL Server picks a nested loop over OplataDocument, minutes instead of a second)
        $paid = [];
        foreach ($this->src->rows('SELECT DISTINCT KeyTab z FROM OplataDocument', ['z']) as $r) {
            $paid[(int) $r['z']] = true;
        }
        $ns = implode(',', array_keys($this->nonSalesTables())) ?: '0';
        foreach ($this->src->rows("SELECT idZakaz z, MIN(D_Dat) / 100 ym, CAST(ROUND(SUM(MySumma) * 100, 0) AS bigint) s FROM R_Doc_Down GROUP BY idZakaz
                HAVING MIN(D_Dat) BETWEEN $a AND $b AND SUM(CASE WHEN St_Key IN ($ns) THEN 1 ELSE 0 END) = 0", ['z', 'ym', 's']) as $r) {
            $k = $ym($r['ym']);
            $m[$k]['lines'] = ($m[$k]['lines'] ?? 0) + (int) $r['s'];
            $m[$k]['itk_n'] = ($m[$k]['itk_n'] ?? 0) + (int) isset($paid[(int) $r['z']]);
        }
        foreach (Db::rows("SELECT substr(day, 1, 7) AS ym, SUM(total) AS s, SUM(EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)) AS n
                FROM orders o WHERE legacy_id LIKE 'itk:order:%' AND status = 'paid' AND deleted = 0 GROUP BY ym") as $r) {
            $m[$r['ym']]['sfx'] = (int) $r['s'];
            $m[$r['ym']]['sfx_n'] = (int) $r['n'];
        }
        ksort($m);
        $f = '%-8s %14s %14s %14s %11s %11s %7s %7s %5s';
        $this->say('Sales per business month (TL): ItKafe report (Stat_DayOplata), ItKafe recount from the lines, Sofrexa (paid orders);');
        $this->say('paid orders: ItKafe (orders with a payment row) vs Sofrexa');
        $this->say(sprintf($f, 'month', 'report', 'lines', 'sofrexa', 'sfx-report', 'sfx-lines', 'itk n', 'sfx n', 'diff'));
        $zero = ['itk' => 0, 'lines' => 0, 'sfx' => 0, 'itk_n' => 0, 'sfx_n' => 0];
        $t = $zero;
        $row = fn(string $k, array $v): string => sprintf($f, $k, self::num($v['itk']), self::num($v['lines']), self::num($v['sfx']),
            self::num($v['sfx'] - $v['itk']), self::num($v['sfx'] - $v['lines']), $v['itk_n'], $v['sfx_n'], $v['sfx_n'] - $v['itk_n']);
        foreach ($m as $k => $v) {
            $v += $zero;
            foreach ($t as $c => $_) {
                $t[$c] += $v[$c];
            }
            $this->say($row($k, $v));
        }
        $this->say($row('total', $t));
        // payments per method: ItKafe's method rows keep the double closes, Sofrexa only the last close
        $p = [];
        foreach ($this->src->rows("SELECT dayId / 100 ym, VidKey v, CAST(ROUND(SUM(SumKOplata) * 100, 0) AS bigint) s FROM Stat_DayOplata
                WHERE VidKey IN (1, 2, 3) AND NoPeople > 0 AND dayId BETWEEN $a AND $b GROUP BY dayId / 100, VidKey", ['ym', 'v', 's']) as $r) {
            $p[$ym($r['ym'])]['itk'][self::METHOD[(int) $r['v']]] = (int) $r['s'];
        }
        foreach (Db::rows("SELECT substr(o.day, 1, 7) AS ym, p.method, SUM(p.amount) AS s FROM payments p JOIN orders o ON o.id = p.order_id
                WHERE o.legacy_id LIKE 'itk:order:%' GROUP BY ym, p.method") as $r) {
            $p[$r['ym']]['sfx'][$r['method']] = (int) $r['s'];
        }
        ksort($p);
        $f = '%-8s %15s %11s %15s %11s %15s %11s';
        $this->say('');
        $this->say('Payments per method (TL): ItKafe and the difference Sofrexa − ItKafe');
        $this->say(sprintf($f, 'month', 'cash', 'diff', 'card', 'diff', 'account', 'diff'));
        $tot = [];
        foreach ($p as $k => $v) {
            $cells = [$k];
            foreach (['cash', 'card', 'account'] as $meth) {
                $i = $v['itk'][$meth] ?? 0;
                $d = ($v['sfx'][$meth] ?? 0) - $i;
                $tot[$meth][0] = ($tot[$meth][0] ?? 0) + $i;
                $tot[$meth][1] = ($tot[$meth][1] ?? 0) + $d;
                array_push($cells, self::num($i), self::num($d));
            }
            $this->say(sprintf($f, ...$cells));
        }
        $this->say(sprintf($f, 'total', self::num($tot['cash'][0] ?? 0), self::num($tot['cash'][1] ?? 0), self::num($tot['card'][0] ?? 0),
            self::num($tot['card'][1] ?? 0), self::num($tot['account'][0] ?? 0), self::num($tot['account'][1] ?? 0)));

        // customer balances, ItKafe's sign (negative = the customer owes)
        $pv = max(1, (int) Settings::get('loyalty.point_value', 100));
        $itk = [];
        foreach ($this->src->rows('SELECT Kl_Key k, CAST(ROUND(SUM(SumObor) * 100, 0) AS bigint) s FROM S_KlientOborot GROUP BY Kl_Key', ['k', 's']) as $r) {
            $itk['itk:customer:' . $r['k']] = (int) $r['s'];
        }
        $acc = Db::pairs("SELECT c.legacy_id, -SUM(a.amount) FROM account_ledger a JOIN customers c ON c.id = a.customer_id WHERE c.legacy_id LIKE 'itk:%' GROUP BY c.legacy_id");
        $pts = Db::pairs("SELECT c.legacy_id, SUM(l.points) FROM loyalty_ledger l JOIN customers c ON c.id = l.customer_id WHERE c.legacy_id LIKE 'itk:%' GROUP BY c.legacy_id");
        $off = 0;
        $maxOff = 0;
        foreach (array_unique([...array_keys($itk), ...array_keys($acc), ...array_keys($pts)]) as $k) {
            $d = (int) ($acc[$k] ?? 0) + (int) ($pts[$k] ?? 0) * $pv - ($itk[$k] ?? 0);
            if (abs($d) > 100) {
                $off++;
            }
            $maxOff = max($maxOff, abs($d));
        }
        $sumPos = static fn(array $a, int $sign): int => array_sum(array_filter(array_map('intval', $a), static fn(int $v): bool => $v * $sign > 0));
        $this->say('');
        $this->say('Customer balances (TL, negative = owes)');
        $this->say(sprintf('  ItKafe   total %15s   owed %15s   in favour %13s   customers %d', self::num(array_sum($itk)), self::num($sumPos($itk, -1)), self::num($sumPos($itk, 1)), count($itk)));
        $this->say(sprintf('  Sofrexa  total %15s   owed %15s   in favour %13s   + points %d = %s', self::num(array_sum(array_map('intval', $acc))),
            self::num($sumPos($acc, -1)), self::num($sumPos($acc, 1)), array_sum(array_map('intval', $pts)), self::num(array_sum(array_map('intval', $pts)) * $pv)));
        $this->say(sprintf('  difference (account + points − ItKafe) %s; customers off by more than 1 TL: %d; largest difference %s',
            self::num(array_sum(array_map('intval', $acc)) + array_sum(array_map('intval', $pts)) * $pv - array_sum($itk)), $off, self::num($maxOff)));

        // stock on hand
        $itkStock = [];
        foreach ($this->src->rows('SELECT A_Key k, CAST(SUM(AS_Ost) AS decimal(18, 4)) q FROM S_Skl_Ass GROUP BY A_Key', ['k', 'q']) as $r) {
            $itkStock['itk:stock:' . $r['k']] = (float) $r['q'];
        }
        $sfxStock = Db::pairs("SELECT s.legacy_id, COALESCE(SUM(m.qty), 0) FROM stock_items s LEFT JOIN stock_moves m ON m.stock_item_id = s.id WHERE s.legacy_id LIKE 'itk:%' GROUP BY s.legacy_id");
        $diff = 0;
        foreach (array_unique([...array_keys($itkStock), ...array_keys($sfxStock)]) as $k) {
            $diff += (int) (abs(($itkStock[$k] ?? 0.0) - (float) ($sfxStock[$k] ?? 0)) >= 0.0005);
        }
        $this->say('');
        $this->say(sprintf('Stock on hand: ItKafe %d items, Σ %s; Sofrexa %d items, Σ %s; items that differ: %d', count($itkStock), number_format(array_sum($itkStock), 3, '.', ''),
            count($sfxStock), number_format(array_sum(array_map('floatval', $sfxStock)), 3, '.', ''), $diff));
    }

    // ------------------------------------------------------------ helpers

    /** Deterministic UUID v7: the row's time (ms) and 74 bits of a hash of its ItKafe key. */
    public static function uuid(int $ms, string $key): string
    {
        $time = substr(pack('J', max(0, $ms)), 2, 6);
        $rand = substr(hash('xxh128', $key, true), 0, 10);
        $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70);
        $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80);
        $hex = bin2hex($time . $rand);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** ItKafe day number (yyyymmdd, not a day count) → Y-m-d; 0 → null. */
    public static function day(int $d): ?string
    {
        return $d > 19000000 ? sprintf('%04d-%02d-%02d', intdiv($d, 10000), intdiv($d, 100) % 100, $d % 100) : null;
    }

    /**
     * K_Diskont holds the card key: an EAN card (13 digits from "2"), a phone used as the card (11 digits from "0",
     * 10 from "5", or "+…") or some other code. Returns [phone, card].
     */
    public static function cardOrPhone(string $v): array
    {
        $v = trim($v);
        if ($v === '') {
            return [null, null];
        }
        if (!preg_match('/^2\d{12}$/', $v) && preg_match('/^(0\d{10}|5\d{9}|\+.+)$/', $v)) {
            return [$v, null];
        }
        return [null, $v];
    }

    /** Role of an ItKafe person: Admin → manager, the "Cashier" access → cashier, else by post (ZZ_Dolgnost). */
    public static function role(int $post, bool $admin, bool $cashier): string
    {
        if ($admin) {
            return 'manager';
        }
        if ($cashier) {
            return 'cashier';
        }
        // 2 administrator, 3 waiter, 4 barman (serves: waiter), 5 dishwasher and 6 cook (kitchen)
        return match ($post) {
            2 => 'manager',
            5, 6 => 'chef',
            default => 'waiter',
        };
    }

    /** S_KlientOborot row kind (Razdel from the Zakaz_Oplata proc, sign ItKafe's). */
    public static function ledgerKind(int $razdel, int $amount): string
    {
        if ($razdel === 1) {
            return $amount < 0 ? 'charge' : 'bonus';      // order on account or paid with bonus / bonus earned
        }
        return $amount > 0 ? 'payment' : 'adjust';       // paid at the cash desk / manual correction
    }

    /** Method of a customer's payment; ItKafe only has it in the note ("Card", "Cash", a bank name …). */
    public static function ledgerMethod(string $note): string
    {
        $n = mb_strtolower($note, 'UTF-8');
        if (preg_match('/kart|card|kredi|visa|master|pos\b/u', $n)) {
            return 'card';
        }
        if (preg_match('/bank|havale|eft\b|transfer|iban|starling|wise|revolut/u', $n)) {
            return 'transfer';
        }
        return 'cash';
    }

    private static function methodLabel(int $vid): string
    {
        return match (self::METHOD[$vid] ?? 'cash') {
            'card' => 'kart',
            'account' => 'cari',
            default => isset(self::FX[$vid]) ? self::FX[$vid] : 'nakit',
        };
    }

    /** Local wall-clock text (CONVERT style 126) → unix ms in the till PC's zone. */
    private function ms(?string $s): ?int
    {
        if ($s === null || strlen($s) < 19 || $s < '1990') {
            return null;
        }
        $h = substr($s, 0, 13);
        $base = $this->hours[$h] ??= (new \DateTimeImmutable(substr($s, 0, 10) . ' ' . substr($s, 11, 2) . ':00:00', $this->tz))->getTimestamp() * 1000;
        return $base + (int) substr($s, 14, 2) * 60_000 + (int) substr($s, 17, 2) * 1000 + (strlen($s) > 20 ? (int) str_pad(substr($s, 20, 3), 3, '0') : 0);
    }

    /** Business day of a time (rollover 05:00). */
    private function dayOf(int $ms): string
    {
        $t = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($this->tz);
        return ((int) $t->format('G') < 5 ? $t->modify('-1 day') : $t)->format('Y-m-d');
    }

    /** End of the last business day of the data (the time of the closing stock moves). */
    private function endMs(): int
    {
        return (new \DateTimeImmutable(self::day($this->lastDat) . ' 04:59:59', $this->tz))->modify('+1 day')->getTimestamp() * 1000;
    }

    /** Runs $fn for each row (with its key), committing every BATCH rows. Returns the number of rows. */
    private function each(iterable $rows, callable $fn): int
    {
        $pdo = Db::pdo();
        $n = 0;
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($rows as $k => $r) {
                $fn($r, $k);
                if (++$n % self::BATCH === 0) {
                    $pdo->exec('COMMIT');
                    $pdo->exec('BEGIN IMMEDIATE');
                }
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
        return $n;
    }

    /** INSERT OR IGNORE with a cached prepared statement. Returns whether the row was new. */
    private function put(string $table, array $row): bool
    {
        $key = $table . ':' . implode(',', array_keys($row));
        if (!isset($this->stmts[$key])) {
            $cols = array_map([Db::class, 'ident'], array_keys($row));
            $this->stmts[$key] = Db::pdo()->prepare('INSERT OR IGNORE INTO ' . Db::ident($table) . ' (' . implode(',', $cols) . ') VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ')');
        }
        $st = $this->stmts[$key];
        $st->execute(array_values($row));
        $new = $st->rowCount() > 0;
        $this->added[$table] = ($this->added[$table] ?? 0) + (int) $new;
        return $new;
    }

    /** name (any language, lower case) → id of the rows of $sql (columns id, names). */
    private function nameIndex(string $sql): array
    {
        $out = [];
        foreach (Db::rows($sql) as $r) {
            foreach (json_arr($r['names']) as $n) {
                if (is_string($n) && trim($n) !== '') {
                    $out[self::norm($n)] ??= $r['id'];
                }
            }
        }
        return $out;
    }

    private function report(string ...$tables): void
    {
        $this->say(implode(', ', array_map(fn(string $t): string => $t . ': ' . ($this->added[$t] ?? 0), $tables)) . ' added');
    }

    private function say(string $m): void
    {
        ($this->out)($m);
    }

    private static function names(string $name): string
    {
        return json_encode(['tr' => $name], JSON_UNESCAPED_UNICODE);
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
    }

    private static function text(?string $s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : $s;
    }

    private static function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn($p): bool => $p !== null && $p !== ''));
        return $parts ? implode(' · ', $parts) : null;
    }

    private static function least(?int ...$v): ?int
    {
        $v = array_filter($v, static fn(?int $x): bool => $x !== null);
        return $v ? min($v) : null;
    }

    /** kuruş → "1.234,50" (Turkish, for notes). */
    private static function tl(int $k): string
    {
        return ($k < 0 ? '-' : '') . number_format(abs($k) / 100, 2, ',', '.');
    }

    /** kuruş → "1234.50" (control tables). */
    private static function num(int $k): string
    {
        return number_format($k / 100, 2, '.', '');
    }

    private static function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',');
    }
}
