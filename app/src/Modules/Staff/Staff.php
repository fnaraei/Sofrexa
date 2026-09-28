<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{Audit, Auth, Clock, Db, HttpError, I18n, Money, Perms, ValidationError};
use Sofrexa\Modules\Orders\Shifts;

/**
 * Staff (ST1–ST4): attendance (clock in / out), today's figures, pay model and payroll.
 * Pay as in ItKafe: fixed salary + commission on sales; the sales counted depend on the person
 * (own bills for waiters, payments taken at the till, the kitchen's or the bar's sales, or all sales),
 * couriers can also get a fee per delivery. Advances and payments go to the payroll ledger (append-only).
 */
final class Staff
{
    public const BASES = ['own', 'till', 'kitchen', 'bar', 'all'];

    /** Permissions of the ST4 switches, in the Figma order. */
    public const SWITCHES = ['orders.take', 'orders.qr_approve', 'bill.print', 'orders.void', 'orders.discount', 'cash.pay', 'orders.transfer'];

    public static function user(string $id): array
    {
        return Db::row('SELECT u.*, r.code AS role_code, r.name AS role_name, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.deleted = 0', [$id])
            ?? throw new HttpError(404);
    }

    // ------------------------------------------------------------ attendance (ST3)

    /** The open clock-in of a user (last entry is "in"), or null. */
    public static function openEntry(string $userId): ?array
    {
        $last = Db::row('SELECT * FROM time_entries WHERE user_id = ? ORDER BY at DESC, rowid DESC LIMIT 1', [$userId]);
        return $last && $last['kind'] === 'in' ? $last : null;
    }

    /**
     * Whether someone has clocked out and not back in (their last entry is "out"). A restaurant that does not use the
     * clock at all has no entries, and then nobody counts as gone.
     */
    public static function offShift(string $userId): bool
    {
        return Db::value('SELECT kind FROM time_entries WHERE user_id = ? ORDER BY at DESC, rowid DESC LIMIT 1', [$userId]) === 'out';
    }

    public static function clockIn(string $userId): int
    {
        $open = self::openEntry($userId);
        if ($open) {
            return (int) $open['at'];
        }
        $at = Clock::ms();
        Db::append('time_entries', ['user_id' => $userId, 'kind' => 'in', 'at' => $at, 'device' => mb_substr((string) ($_COOKIE['sofrexa_device'] ?? ''), 0, 60) ?: null]);
        Audit::log('staff.clock_in', (string) Db::value('SELECT name FROM users WHERE id = ?', [$userId]), 'user', $userId);
        return $at;
    }

    public static function clockOut(string $userId): int
    {
        $open = self::openEntry($userId);
        if (!$open) {
            throw new \InvalidArgumentException(I18n::t('staff.err_not_in'));
        }
        $at = Clock::ms();
        Db::append('time_entries', ['user_id' => $userId, 'kind' => 'out', 'at' => $at, 'device' => mb_substr((string) ($_COOKIE['sofrexa_device'] ?? ''), 0, 60) ?: null]);
        \Sofrexa\Modules\Orders\Notify::release($userId);
        Audit::log('staff.clock_out', (string) Db::value('SELECT name FROM users WHERE id = ?', [$userId]) . ' · ' . self::duration($at - (int) $open['at'], 'tr'), 'user', $userId);
        return $at - (int) $open['at'];
    }

    /** user_id => clock-in time (ms) of everyone on shift now. */
    public static function onShift(): array
    {
        return Db::pairs("SELECT t.user_id, t.at FROM time_entries t
            WHERE t.kind = 'in' AND t.rowid = (SELECT x.rowid FROM time_entries x WHERE x.user_id = t.user_id ORDER BY x.at DESC, x.rowid DESC LIMIT 1)");
    }

    /** How many people worked today (clocked in this business day, or still on shift from before). */
    public static function inToday(): int
    {
        $ids = array_column(Db::rows("SELECT DISTINCT user_id FROM time_entries WHERE kind = 'in' AND at >= ?", [self::dayStart()]), 'user_id');
        return count(array_unique([...$ids, ...array_map('strval', array_keys(self::onShift()))]));
    }

    /** Past shifts, newest first: [in, out, ms]. The open one is left out. */
    public static function history(string $userId, int $limit = 7): array
    {
        $rows = Db::rows('SELECT kind, at FROM time_entries WHERE user_id = ? ORDER BY at DESC, rowid DESC LIMIT ' . ($limit * 2 + 2), [$userId]);
        $out = [];
        $end = null;
        foreach ($rows as $r) {
            if ($r['kind'] === 'out') {
                $end = (int) $r['at'];
            } elseif ($end !== null) {
                $out[] = ['in' => (int) $r['at'], 'out' => $end, 'ms' => $end - (int) $r['at']];
                $end = null;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Worked time in a period (ms), open shift counted up to now. */
    public static function worked(string $userId, int $from, int $to): int
    {
        $ms = 0;
        $in = null;
        foreach (Db::rows('SELECT kind, at FROM time_entries WHERE user_id = ? AND at < ? ORDER BY at, rowid', [$userId, $to]) as $r) {
            if ($r['kind'] === 'in') {
                $in ??= (int) $r['at'];
            } elseif ($in !== null) {
                $ms += max(0, min((int) $r['at'], $to) - max($in, $from));
                $in = null;
            }
        }
        if ($in !== null) {
            $ms += max(0, min(Clock::ms(), $to) - max($in, $from));
        }
        return $ms;
    }

    /** "8 sa 10 dk", "7 sa", "45 dk". */
    public static function duration(int $ms, ?string $lang = null): string
    {
        $min = intdiv(max(0, $ms), 60_000);
        [$h, $m] = [intdiv($min, 60), $min % 60];
        $s = $h > 0 && $m > 0 ? I18n::t('staff.dur_hm', ['h' => $h, 'm' => $m], $lang) : ($h > 0 ? I18n::t('staff.dur_h', ['h' => $h], $lang) : I18n::t('staff.dur_m', ['m' => $m], $lang));
        return ($lang ?? I18n::lang()) === 'fa' ? I18n::faDigits($s) : $s;
    }

    /** Start of the business day (ms), after the rollover hour. */
    public static function dayStart(): int
    {
        $roll = (int) \Sofrexa\Core\Settings::get('day.rollover_hour', 5);
        $t = strtotime(date('Y-m-d') . sprintf(' %02d:00:00', $roll));
        return ((int) date('G') < $roll ? $t - 86400 : $t) * 1000;
    }

    // ------------------------------------------------------------ figures

    /**
     * Sales a person's commission is counted on, in [$from, $to) (kuruş).
     * own: bills they served · till: payments they took · kitchen / bar: that station's lines · all: every bill.
     * Voided lines are never counted; a free staff meal has a zero total.
     */
    public static function sales(array $u, int $from, int $to): int
    {
        return match ($u['pay_basis'] ?? 'own') {
            'till' => (int) Db::value("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.user_id = ? AND p.at >= ? AND p.at < ? AND o.deleted = 0", [$u['id'], $from, $to]),
            'kitchen', 'bar' => (int) Db::value("SELECT COALESCE(SUM(ROUND((i.qty * (i.unit_price + i.mods_price)) * (1.0 - CASE WHEN o.subtotal > 0 THEN o.discount * 1.0 / o.subtotal ELSE 0 END))), 0)
                FROM order_items i JOIN orders o ON o.id = i.order_id
                WHERE o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND o.closed_at < ? AND i.deleted = 0 AND i.status <> 'void' AND i.station = ?", [$from, $to, $u['pay_basis']]),
            'all' => (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ?", [$from, $to]),
            default => (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE waiter_id = ? AND status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ?", [$u['id'], $from, $to]),
        };
    }

    /** Delivered orders of a courier in [$from, $to). */
    public static function deliveries(string $userId, int $from, int $to): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM orders WHERE channel IN ('delivery', 'online') AND status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ?
            AND json_extract(delivery, '$.courier_id') = ?", [$from, $to, $userId]);
    }

    /** Tables (bills) a waiter opened today. */
    public static function tablesToday(string $userId): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM orders WHERE waiter_id = ? AND channel IN ('table', 'qr') AND status <> 'void' AND deleted = 0 AND opened_at >= ?", [$userId, self::dayStart()]);
    }

    /** ST3 tiles: today's sales on the person's basis, tables, estimated commission. */
    public static function today(array $u): array
    {
        $from = self::dayStart();
        $sales = self::sales($u, $from, Clock::ms() + 1);
        $del = self::deliveries($u['id'], $from, Clock::ms() + 1);
        return ['sales' => $sales, 'tables' => self::tablesToday($u['id']), 'deliveries' => $del,
            'bonus' => self::commission($sales, (float) $u['commission_pct']) + $del * (int) $u['per_delivery']];
    }

    /** Commission in whole lira (kuruş value), as the pay sheet shows it. */
    public static function commission(int $sales, float $pct): int
    {
        return (int) round($sales * $pct / 10000) * 100;
    }

    /** "Sabit + %1 mutfak", "%3 satış", "Teslimat başı". */
    public static function payModel(array $u): string
    {
        $parts = [];
        if ((int) $u['base_salary'] > 0) {
            $parts[] = t('staff.pm_fixed');
        }
        if ((float) $u['commission_pct'] > 0) {
            $parts[] = '%' . digits(I18n::numAuto((float) $u['commission_pct'])) . ' ' . t('staff.basis_s.' . ($u['pay_basis'] ?: 'own'));
        }
        if ((int) ($u['per_delivery'] ?? 0) > 0) {
            $parts[] = t('staff.pm_delivery');
        }
        return $parts ? implode(' + ', $parts) : '—';
    }

    // ------------------------------------------------------------ list (ST1)

    /** Staff with role, state on shift, pay model and today's figure. $filter: active | shift | left | role:<code>. */
    public static function list(string $filter = 'active'): array
    {
        $on = self::onShift();
        $out = self::couriersOut();
        $rows = [];
        foreach (Db::rows('SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.deleted = 0 ORDER BY u.active DESC, r.sort, u.sort, u.name') as $u) {
            $u['on_since'] = isset($on[$u['id']]) ? (int) $on[$u['id']] : null;
            $u['state'] = !$u['active'] ? 'left' : (isset($out[$u['id']]) ? 'way' : ($u['on_since'] ? 'shift' : 'off'));
            $u['role_label'] = Users::roleLabel($u['role_code'], $u['role_name']);
            $keep = match (true) {
                $filter === 'shift' => $u['on_since'] !== null && $u['active'],
                $filter === 'left' => !$u['active'],
                str_starts_with($filter, 'role:') => $u['active'] && $u['role_code'] === substr($filter, 5),
                default => (bool) $u['active'],
            };
            if ($keep) {
                $rows[] = $u;
            }
        }
        return $rows;
    }

    /** Couriers with orders on the way: user_id => count. */
    private static function couriersOut(): array
    {
        $n = [];
        foreach (Db::rows("SELECT delivery FROM orders WHERE channel IN ('delivery', 'online') AND status IN ('open', 'billed') AND deleted = 0 AND delivery IS NOT NULL") as $o) {
            $d = json_arr($o['delivery']);
            if (($d['stage'] ?? '') === 'way' && !empty($d['courier_id'])) {
                $n[$d['courier_id']] = ($n[$d['courier_id']] ?? 0) + 1;
            }
        }
        return $n;
    }

    /** Filter chip counts: active, on shift, per role (active only), left. */
    public static function counts(): array
    {
        $active = Db::pairs('SELECT id, active FROM users WHERE deleted = 0');
        $c = ['all' => 0, 'active' => 0, 'shift' => count(array_filter(array_keys(self::onShift()), static fn($id): bool => !empty($active[$id]))), 'left' => 0, 'roles' => []];
        foreach (Db::rows('SELECT u.active, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.deleted = 0') as $r) {
            $c['all']++;
            if ($r['active']) {
                $c['active']++;
                $c['roles'][$r['code']] = ($c['roles'][$r['code']] ?? 0) + 1;
            } else {
                $c['left']++;
            }
        }
        return $c;
    }

    // ------------------------------------------------------------ edit (ST4)

    /** Effective state of the ST4 switches for a user: perm => on. */
    public static function switches(array $u): array
    {
        $role = json_arr($u['role_perms'] ?? '[]');
        $eff = in_array('*', $role, true) ? ['*'] : array_diff(Perms::expand(array_unique([...$role, ...json_arr($u['perms_allow'])])), json_arr($u['perms_deny']));
        $out = [];
        foreach (self::SWITCHES as $p) {
            $out[$p] = in_array('*', $eff, true) || in_array($p, $eff, true);
        }
        return $out;
    }

    /**
     * ST4 save: role, commission, fixed salary, basis, per-delivery fee and the permission switches
     * (stored as allow / deny on top of the role).
     */
    public static function saveProfile(string $id, array $in): void
    {
        $u = self::user($id);
        $role = Db::row('SELECT id, code, perms FROM roles WHERE id = ? AND deleted = 0', [(string) ($in['role_id'] ?? $u['role_id'])]);
        if (!$role) {
            throw new ValidationError(['role_id' => I18n::t('users.err_role')]);
        }
        if ($u['role_code'] === 'manager' && $role['code'] !== 'manager' && $u['active'] && (int) Db::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'manager' AND u.active = 1 AND u.deleted = 0") <= 1) {
            throw new ValidationError(['role_id' => I18n::t('users.err_last_manager')]);
        }
        $pct = (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', strtr((string) ($in['commission_pct'] ?? '0'), ['٫' => ',', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])) ?? '0');
        $row = [
            'id' => $id,
            'role_id' => $role['id'],
            'commission_pct' => round(max(0, min(100, $pct)), 2),
            'base_salary' => max(0, Money::parse((string) ($in['base_salary'] ?? '0'))),
            'pay_basis' => in_array($in['pay_basis'] ?? '', self::BASES, true) ? $in['pay_basis'] : ($u['pay_basis'] ?: 'own'),
            'per_delivery' => max(0, Money::parse((string) ($in['per_delivery'] ?? '0'))),
        ];
        if (!empty($in['hired_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['hired_on'])) {
            $row['hired_on'] = $in['hired_on'];
        }
        $rolePerms = json_arr($role['perms']);
        // the switches are shown off-limits for a manager; they only apply to the other roles
        if ($u['role_code'] !== 'manager' && !in_array('*', $rolePerms, true) && isset($in['perms']) && is_array($in['perms'])) {
            $base = Perms::expand($rolePerms);
            $allow = array_diff(json_arr($u['perms_allow']), self::SWITCHES);
            $deny = array_diff(json_arr($u['perms_deny']), self::SWITCHES);
            foreach (self::SWITCHES as $p) {
                $on = !empty($in['perms'][$p]);
                if ($on && !in_array($p, $base, true)) {
                    $allow[] = $p;
                } elseif (!$on && in_array($p, $base, true)) {
                    $deny[] = $p;
                }
            }
            $row['perms_allow'] = json_encode(array_values(array_unique($allow)));
            $row['perms_deny'] = json_encode(array_values(array_unique($deny)));
        }
        Db::save('users', $row);
        $changed = array_keys(array_filter($row, static fn($v, string $k): bool => $k !== 'id' && (string) ($u[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            Audit::log('user.save', $u['name'] . ' · ' . implode(', ', $changed), 'user', $id, ['changes' => $changed]);
        }
    }

    // ------------------------------------------------------------ payroll (ST2)

    /** [from, to) of a 'Y-m' month in ms; the current month ends now. */
    public static function period(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $from = (int) strtotime($month . '-01 00:00:00');
        return [$from * 1000, (int) strtotime('+1 month', $from) * 1000];
    }

    /**
     * One row per active person with a pay model (or anything paid this month):
     * sales, rate, fixed, deliveries, earned, paid (advances and payments), left.
     */
    public static function payroll(string $month): array
    {
        [$from, $to] = self::period($month);
        $paid = Db::pairs('SELECT user_id, SUM(total) FROM payroll WHERE period = ? GROUP BY user_id', [$month]);
        $rows = [];
        foreach (Db::rows("SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.deleted = 0 ORDER BY r.sort, u.sort, u.name") as $u) {
            $has = (int) $u['base_salary'] > 0 || (float) $u['commission_pct'] > 0 || (int) $u['per_delivery'] > 0;
            if (!($u['active'] && $has) && !isset($paid[$u['id']])) {
                continue;
            }
            $sales = (float) $u['commission_pct'] > 0 ? self::sales($u, $from, $to) : 0;
            $del = (int) $u['per_delivery'] > 0 ? self::deliveries($u['id'], $from, $to) : 0;
            $earned = (int) $u['base_salary'] + self::commission($sales, (float) $u['commission_pct']) + $del * (int) $u['per_delivery'];
            $p = (int) ($paid[$u['id']] ?? 0);
            $rows[] = $u + ['role_label' => Users::roleLabel($u['role_code'], $u['role_name']), 'sales' => $sales, 'deliveries' => $del,
                'earned' => $earned, 'paid' => $p, 'left' => $earned - $p];
        }
        return $rows;
    }

    public static function payrollTotals(array $rows): array
    {
        $rates = [];
        foreach ($rows as $r) {
            if ($r['role_code'] === 'waiter' && (float) $r['commission_pct'] > 0) {
                $k = (string) (float) $r['commission_pct'];
                $rates[$k] = ($rates[$k] ?? 0) + 1;
            }
        }
        arsort($rates);
        return ['earned' => array_sum(array_column($rows, 'earned')), 'paid' => array_sum(array_column($rows, 'paid')),
            'left' => array_sum(array_column($rows, 'left')), 'waiter_rate' => $rates ? (float) array_key_first($rates) : null];
    }

    /**
     * Pays people for a month ("Seçilenlere ödeme"): $amounts user_id => kuruş. Cash from the till leaves the drawer
     * (a cash move in the open shift); a bank transfer does not. Before the month is over it is an advance.
     */
    public static function pay(string $month, array $amounts, string $method, string $note = ''): int
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new ValidationError(['month' => I18n::t('staff.err_month')]);
        }
        $method = $method === 'cash' ? 'cash' : 'bank';
        $amounts = array_filter(array_map('intval', $amounts), static fn(int $a): bool => $a > 0);
        if (!$amounts) {
            throw new ValidationError(['amount' => I18n::t('staff.err_amount')]);
        }
        if ($method === 'cash' && !Shifts::currentId()) {
            throw new \InvalidArgumentException(I18n::t('order.err_no_shift'));
        }
        $kind = $month >= date('Y-m') ? 'advance' : 'payment';
        $by = Auth::user()['id'] ?? null;
        Db::tx(static function () use ($amounts, $month, $method, $note, $kind, $by): void {
            foreach ($amounts as $uid => $amount) {
                $name = (string) Db::value('SELECT name FROM users WHERE id = ? AND deleted = 0', [(string) $uid]);
                if ($name === '') {
                    throw new HttpError(404);
                }
                $pid = Db::append('payroll', ['user_id' => (string) $uid, 'period' => $month, 'kind' => $kind, 'total' => $amount, 'method' => $method,
                    'note' => mb_substr(trim($note), 0, 200) ?: null, 'at' => Clock::ms(), 'user_by' => $by]);
                if ($method === 'cash') {
                    Shifts::move('out', 'TRY', $amount, ($kind === 'advance' ? 'Avans' : 'Maaş') . ' · ' . $name, $note !== '' ? $note : null, null, null, null, 'payroll:' . $pid);
                }
                Audit::log('staff.pay', $name . ' · ' . Money::fmt($amount, false, 'tr') . ' · ' . $kind . ' · ' . $method . ' · ' . $month, 'user', (string) $uid);
            }
        });
        return array_sum($amounts);
    }

    /** CSV of the payroll (Excel). */
    public static function csv(string $month, array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Dönem', 'Personel', 'Rol', 'Satış', 'Oran %', 'Sabit', 'Teslimat', 'Hakediş', 'Ödenen', 'Kalan'], ';', '"', '');
        $m = static fn(int $k): string => number_format($k / 100, 2, ',', '.');
        foreach ($rows as $r) {
            fputcsv($h, [$month, $r['name'], I18n::t('role.' . $r['role_code'], [], 'tr'), $m($r['sales']), str_replace('.', ',', (string) (float) $r['commission_pct']),
                $m((int) $r['base_salary']), $r['deliveries'], $m($r['earned']), $m($r['paid']), $m($r['left'])], ';', '"', '');
        }
        rewind($h);
        return (string) stream_get_contents($h);
    }

    /** The person's own year of joining (hired_on, else the account). */
    public static function since(array $u): ?int
    {
        if (!empty($u['hired_on'])) {
            return (int) substr((string) $u['hired_on'], 0, 4);
        }
        return $u['created_at'] ? (int) date('Y', intdiv((int) $u['created_at'], 1000)) : null;
    }
}
