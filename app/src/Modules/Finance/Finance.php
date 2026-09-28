<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Finance;

use Sofrexa\Core\{Audit, Auth, Clock, Db, HttpError, I18n, Money, Settings, ValidationError};
use Sofrexa\Modules\Orders\{Rates, Shifts};
use Sofrexa\Modules\Reports\Reports;

/**
 * Income and expenses (FI1–FI4). Entered by hand in finance_entries (append-only; a mistake is reversed),
 * plus what other parts already know: purchase invoices (stock), salaries (payroll) and cash taken out of the till
 * for expenses. Recurring expenses (rent, accounting …) are booked by the nightly job on their day.
 * The profit and loss statement uses the recipe cost of what was sold, not the purchases (those go to stock).
 */
final class Finance
{
    public const INCOME = ['event', 'rent_in', 'other_in'];
    public const METHODS = ['cash', 'bank', 'card'];

    /** Till "out" reasons that are expenses, by their i18n key → category. */
    private const TILL_REASONS = ['moves.r_market' => 'supplies', 'moves.r_supplier' => 'supplies', 'moves.r_courier' => 'other', 'moves.r_other' => 'other'];

    public static function categories(): array
    {
        return array_values(array_filter((array) Settings::get('finance.categories', []), static fn($c): bool => is_string($c) && $c !== ''));
    }

    public static function catLabel(string $c): string
    {
        return I18n::has('fin.cat.' . $c) ? t('fin.cat.' . $c) : $c;
    }

    // ------------------------------------------------------------ entries

    /**
     * Every income and expense of the period, newest first:
     * id, at, day, kind (expense | income), category, text, method, source (manual | recurring | stock | payroll | till), amount, receipt, own.
     */
    public static function entries(int $from, int $to): array
    {
        $fromDay = date('Y-m-d', intdiv($from, 1000));
        $toDay = date('Y-m-d', intdiv($to - 1, 1000));
        $rows = [];
        // by hand and recurring (reversed ones and their reversals left out)
        foreach (Db::rows("SELECT f.* FROM finance_entries f WHERE f.day >= ? AND f.day <= ? AND f.reverses IS NULL
            AND NOT EXISTS (SELECT 1 FROM finance_entries r WHERE r.reverses = f.id)", [$fromDay, $toDay]) as $f) {
            $rows[] = ['id' => $f['id'], 'at' => (int) $f['at'], 'day' => $f['day'], 'kind' => $f['kind'], 'category' => $f['category'], 'text' => (string) $f['description'],
                'method' => $f['method'], 'source' => $f['source'], 'amount' => (int) $f['amount'], 'receipt' => $f['receipt'], 'own' => true,
                'fx' => $f['currency'] !== 'TRY' ? ['cur' => $f['currency'], 'fx' => (float) $f['amount_fx']] : null];
        }
        // purchase invoices
        foreach (Db::rows("SELECT d.*, s.name AS supplier FROM stock_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id WHERE d.kind = 'purchase' AND d.day >= ? AND d.day <= ?", [$fromDay, $toDay]) as $d) {
            $rows[] = ['id' => $d['id'], 'at' => (int) $d['at'], 'day' => $d['day'], 'kind' => 'expense', 'category' => 'supplies',
                'text' => trim(($d['supplier'] ?: t('fin.no_supplier')) . ($d['doc_no'] ? ' · ' . t('fin.invoice_no', ['no' => $d['doc_no']]) : ''), ' ·'),
                'method' => $d['pay_method'] === 'cash' ? 'cash' : ($d['pay_method'] === 'card' ? 'card' : 'bank'), 'source' => 'stock', 'amount' => (int) $d['total'], 'receipt' => null, 'own' => false, 'fx' => null];
        }
        // salaries and advances, one line per day and method (imported ItKafe accruals are not money that moved)
        foreach (Db::rows("SELECT p.at, p.period, p.kind, p.method, p.total, u.name FROM payroll p LEFT JOIN users u ON u.id = p.user_id WHERE p.at >= ? AND p.at < ? AND p.kind <> 'accrual' ORDER BY p.at", [$from, $to]) as $p) {
            $day = date('Y-m-d', intdiv((int) $p['at'], 1000));
            $k = 'pay:' . $day . ':' . $p['method'] . ':' . $p['kind'];
            if (!isset($rows[$k])) {
                $rows[$k] = ['id' => $k, 'at' => (int) $p['at'], 'day' => $day, 'kind' => 'expense', 'category' => 'staff', 'text' => '', 'names' => [],
                    'method' => $p['method'] === 'cash' ? 'cash' : 'bank', 'source' => 'payroll', 'amount' => 0, 'receipt' => null, 'own' => false, 'fx' => null, 'pkind' => $p['kind'], 'period' => $p['period']];
            }
            $rows[$k]['amount'] += (int) $p['total'];
            $rows[$k]['names'][] = first_name((string) $p['name']);
        }
        foreach ($rows as $k => $r) {
            if (($r['source'] ?? '') === 'payroll') {
                $n = count(array_unique($r['names']));
                $rows[$k]['text'] = t($r['pkind'] === 'advance' ? 'fin.pay_adv' : 'fin.pay_sal', ['n' => digits($n)]) . ($n <= 3 ? ' · ' . implode(', ', array_unique($r['names'])) : '');
            }
        }
        // cash taken from the till for expenses (not purchases, salaries or entries booked above; not reversed)
        $reasons = self::tillReasons();
        foreach (Db::rows("SELECT m.* FROM cash_moves m WHERE m.kind = 'out' AND m.at >= ? AND m.at < ? AND m.ref IS NULL AND m.reverses IS NULL
            AND NOT EXISTS (SELECT 1 FROM cash_moves r WHERE r.reverses = m.id)", [$from, $to]) as $m) {
            $cat = $reasons[mb_strtolower(trim((string) $m['reason']), 'UTF-8')] ?? null;
            if ($cat === null) {
                continue;
            }
            $rows[] = ['id' => $m['id'], 'at' => (int) $m['at'], 'day' => date('Y-m-d', intdiv((int) $m['at'], 1000)), 'kind' => 'expense', 'category' => $cat,
                'text' => trim($m['reason'] . ($m['note'] ? ' · ' . $m['note'] : '')), 'method' => 'cash', 'source' => 'till', 'amount' => -(int) $m['amount'],
                'receipt' => $m['photo'], 'own' => false, 'fx' => null];
        }
        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b): int => [$b['day'], $b['at']] <=> [$a['day'], $a['at']]);
        return $rows;
    }

    /** Every language's text of the till expense reasons → category. */
    private static function tillReasons(): array
    {
        $map = [];
        foreach (self::TILL_REASONS as $key => $cat) {
            foreach (array_keys(I18n::LANGS) as $l) {
                $map[mb_strtolower(I18n::t($key, [], $l), 'UTF-8')] = $cat;
            }
        }
        return $map;
    }

    /** FI1 head figures and category bars. */
    public static function summary(array $rows, int $from, int $to): array
    {
        $exp = array_filter($rows, static fn(array $r): bool => $r['kind'] === 'expense');
        $inc = array_filter($rows, static fn(array $r): bool => $r['kind'] === 'income');
        $cats = [];
        foreach ($exp as $r) {
            $cats[$r['category']] = ($cats[$r['category']] ?? 0) + $r['amount'];
        }
        arsort($cats);
        $k = Reports::kpis($from, $to);
        $recurring = self::recurring();
        return [
            'net_sales' => $k['net'], 'other_income' => array_sum(array_column($inc, 'amount')),
            'expense' => array_sum(array_column($exp, 'amount')), 'expense_n' => count($exp),
            'cash_out' => array_sum(array_map(static fn(array $r): int => $r['method'] === 'cash' ? $r['amount'] : 0, $exp)),
            'recurring' => array_sum(array_column($recurring, 'amount')), 'recurring_n' => count($recurring),
            'no_receipt' => count(array_filter($exp, static fn(array $r): bool => $r['own'] && !$r['receipt'] && $r['source'] === 'manual')),
            'cats' => $cats,
        ];
    }

    /**
     * Records an income or expense (FI2). Paid from the till: the cash leaves the drawer (a cash move tied to the entry).
     * "Her ay tekrarla" also makes a recurring expense on that day of the month.
     */
    public static function add(array $in, ?string $receipt = null): string
    {
        $kind = ($in['kind'] ?? '') === 'income' ? 'income' : 'expense';
        $cats = $kind === 'income' ? self::INCOME : self::categories();
        $cat = (string) ($in['category'] ?? '');
        $cur = strtoupper((string) ($in['currency'] ?? 'TRY'));
        $method = in_array($in['method'] ?? '', self::METHODS, true) ? $in['method'] : 'bank';
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['day'] ?? '')) ? $in['day'] : date('Y-m-d');
        $err = [];
        if (!in_array($cat, $cats, true)) {
            $err['category'] = I18n::t('fin.err_category');
        }
        $fx = 0.0;
        if ($cur === 'TRY') {
            $amount = Money::parse((string) ($in['amount'] ?? ''));
        } else {
            $fx = round((float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string) ($in['amount'] ?? '')) ?? ''), 2);
            $rate = Rates::latest()[$cur] ?? null;
            if (!$rate) {
                $err['amount'] = I18n::t('order.err_rate', ['cur' => $cur]);
            }
            $amount = $rate ? Money::toTry($fx, (float) $rate) : 0;
        }
        if ($amount <= 0 && !isset($err['amount'])) {
            $err['amount'] = I18n::t('fin.err_amount');
        }
        if ($method === 'cash' && $kind === 'expense' && !Shifts::currentId()) {
            $err['method'] = I18n::t('fin.err_no_shift');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        $text = mb_substr(trim((string) ($in['description'] ?? '')), 0, 200);
        $uid = Auth::user()['id'] ?? null;
        $id = Db::tx(static function () use ($kind, $cat, $text, $day, $amount, $cur, $fx, $method, $receipt, $uid, $in): string {
            $recurring = null;
            if ($kind === 'expense' && !empty($in['recurring'])) {
                $recurring = Db::save('recurring_expenses', ['category' => $cat, 'description' => $text ?: null, 'amount' => $amount,
                    'day_of_month' => min(28, (int) substr($day, 8, 2)), 'method' => $method === 'cash' ? 'bank' : $method, 'active' => 1, 'last_run' => substr($day, 0, 7)]);
            }
            $id = Db::append('finance_entries', ['kind' => $kind, 'category' => $cat, 'description' => $text ?: null, 'day' => $day, 'amount' => $amount,
                'currency' => $cur, 'amount_fx' => $fx, 'method' => $method, 'source' => 'manual', 'receipt' => $receipt, 'recurring_id' => $recurring, 'user_id' => $uid, 'at' => Clock::ms()]);
            if ($method === 'cash' && Shifts::currentId()) {
                Shifts::move($kind === 'expense' ? 'out' : 'in', $cur, $cur === 'TRY' ? $amount : $fx, I18n::t('fin.cat_' . $kind, [], 'tr') . ' · ' . self::catLabelTr($cat), $text ?: null, null, null, $receipt, 'finance:' . $id);
            }
            return $id;
        });
        Audit::log('finance.' . $kind, self::catLabelTr($cat) . ' · ' . Money::fmt($amount, false, 'tr') . ($text !== '' ? ' · ' . $text : '') . ' · ' . $method, 'finance', $id);
        return $id;
    }

    private static function catLabelTr(string $c): string
    {
        return I18n::has('fin.cat.' . $c) ? I18n::t('fin.cat.' . $c, [], 'tr') : $c;
    }

    /** Cancels an entry made by hand (a reversal row; the cash move, if any, is reversed too). */
    public static function reverse(string $id): void
    {
        $f = Db::row('SELECT * FROM finance_entries WHERE id = ? AND reverses IS NULL', [$id]) ?? throw new HttpError(404);
        if (Db::value('SELECT 1 FROM finance_entries WHERE reverses = ?', [$id])) {
            return;
        }
        Db::tx(static function () use ($f): void {
            Db::append('finance_entries', ['kind' => $f['kind'], 'category' => $f['category'], 'description' => $f['description'], 'day' => $f['day'], 'amount' => -(int) $f['amount'],
                'currency' => $f['currency'], 'amount_fx' => -(float) $f['amount_fx'], 'method' => $f['method'], 'source' => $f['source'], 'reverses' => $f['id'],
                'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
            $move = Db::row("SELECT * FROM cash_moves WHERE ref = ? AND reverses IS NULL", ['finance:' . $f['id']]);
            if ($move && !Db::value('SELECT 1 FROM cash_moves WHERE reverses = ?', [$move['id']]) && Shifts::currentId()) {
                Shifts::reverse($move['id']);
            }
        });
        Audit::log('finance.reverse', self::catLabelTr($f['category']) . ' · ' . Money::fmt((int) $f['amount'], false, 'tr') . ($f['description'] ? ' · ' . $f['description'] : ''), 'finance', $f['id']);
    }

    public static function entry(string $id): array
    {
        return Db::row('SELECT f.*, u.name AS user_name FROM finance_entries f LEFT JOIN users u ON u.id = f.user_id WHERE f.id = ?', [$id]) ?? throw new HttpError(404);
    }

    // ------------------------------------------------------------ recurring

    /** Turkish suffix of a day number ("1’i", "6’sı", "10’u", "20’si"). */
    public static function trDay(int $d): string
    {
        $ones = [1 => 'i', 2 => 'si', 3 => 'ü', 4 => 'ü', 5 => 'i', 6 => 'sı', 7 => 'si', 8 => 'i', 9 => 'u'];
        $tens = [1 => 'u', 2 => 'si', 3 => 'u'];
        return $d % 10 ? $ones[$d % 10] : ($tens[intdiv($d, 10)] ?? 'i');
    }

    public static function recurring(): array
    {
        return Db::rows('SELECT * FROM recurring_expenses WHERE deleted = 0 AND active = 1 ORDER BY day_of_month, amount DESC');
    }

    public static function stopRecurring(string $id): void
    {
        $r = Db::row('SELECT * FROM recurring_expenses WHERE id = ? AND deleted = 0', [$id]) ?? throw new HttpError(404);
        Db::save('recurring_expenses', ['id' => $id, 'active' => 0]);
        Audit::log('finance.recurring_stop', self::catLabelTr($r['category']) . ' · ' . Money::fmt((int) $r['amount'], false, 'tr'), 'finance', $id);
    }

    /** Nightly: books the recurring expenses whose day has come this month. Returns how many. */
    public static function runRecurring(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $month = substr($today, 0, 7);
        $n = 0;
        foreach (self::recurring() as $r) {
            if ($r['last_run'] === $month || (int) substr($today, 8, 2) < (int) $r['day_of_month']) {
                continue;
            }
            Db::tx(static function () use ($r, $month): void {
                Db::append('finance_entries', ['kind' => 'expense', 'category' => $r['category'], 'description' => $r['description'], 'day' => $month . sprintf('-%02d', (int) $r['day_of_month']),
                    'amount' => (int) $r['amount'], 'currency' => 'TRY', 'amount_fx' => 0, 'method' => $r['method'], 'source' => 'recurring', 'recurring_id' => $r['id'], 'at' => Clock::ms()]);
                Db::save('recurring_expenses', ['id' => $r['id'], 'last_run' => $month]);
            });
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------ profit and loss (FI3 / FI4)

    /** Operating expense lines of the statement (key => categories). */
    private const OPEX = ['staff' => ['staff'], 'rent' => ['rent'], 'energy' => ['energy'], 'marketing' => ['marketing'], 'maintenance' => ['maintenance'],
        'other' => ['accounting', 'tax', 'other', 'supplies']];

    /** The statement of a period; amounts VAT excluded (sales), expenses as booked. */
    public static function pl(int $from, int $to): array
    {
        $k = Reports::kpis($from, $to);
        $rows = self::entries($from, $to);
        $other = 0;
        $opex = array_fill_keys(array_keys(self::OPEX), 0);
        foreach ($rows as $r) {
            if ($r['kind'] === 'income') {
                $other += $r['amount'];
                continue;
            }
            if ($r['source'] === 'stock') {
                continue; // purchases go to stock; the cost of what was sold is counted instead
            }
            foreach (self::OPEX as $line => $cats) {
                if (in_array($r['category'], $cats, true)) {
                    $opex[$line] += $r['amount'];
                    continue 2;
                }
            }
            $opex['other'] += $r['amount'];
        }
        $cogs = Reports::costOfSales($from, $to);
        $waste = Reports::waste($from, $to);
        $gross = $k['net'] + $other - $cogs - $waste;
        $opexTotal = array_sum($opex);
        $netProfit = $gross - $opexTotal;
        $income = $k['net'] + $other;
        return ['gross_sales' => $k['sales'], 'vat' => $k['vat'], 'net_sales' => $k['net'], 'other' => $other, 'cogs' => $cogs, 'waste' => $waste,
            'gross' => $gross, 'opex' => $opex, 'opex_total' => $opexTotal, 'net' => $netProfit, 'income' => $income,
            'margin' => $income > 0 ? $netProfit * 100 / $income : null, 'gross_margin' => $k['net'] > 0 ? $gross * 100 / $k['net'] : null,
            'purchases' => array_sum(array_map(static fn(array $r): int => $r['source'] === 'stock' ? $r['amount'] : 0, $rows))];
    }

    /** Where the income goes: cost, waste, staff, rent, other, profit (shares of the income). */
    public static function split(array $pl): array
    {
        $inc = max(1, $pl['income']);
        $other = $pl['opex_total'] - $pl['opex']['staff'] - $pl['opex']['rent'];
        $parts = ['cogs' => $pl['cogs'], 'waste' => $pl['waste'], 'staff' => $pl['opex']['staff'], 'rent' => $pl['opex']['rent'], 'other' => $other, 'profit' => max(0, $pl['net'])];
        return array_map(static fn(int $v): float => max(0, $v) * 100 / $inc, $parts);
    }
}
