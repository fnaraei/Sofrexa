<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Customers;

use Sofrexa\Core\{Audit, Auth, Clock, Db, HttpError, I18n, Money, ValidationError};

/**
 * Customers: phone lookup at the till (C4), quick registration by the cashier, delivery addresses,
 * the list (CU1/CU3), the account statement (CU2/CU4) and the purchase history.
 * The phone is stored as typed and normalised (phone_norm) for matching.
 */
final class Customers
{
    /** Paid bills that make a customer a regular ("Müdavim"). */
    public const REGULAR_ORDERS = 10;

    public const TAG_TONES = ['regular' => 'accent', 'credit' => 'warning', 'online' => 'info', 'blacklist' => 'danger'];

    public static function get(string $id): ?array
    {
        return Db::row('SELECT * FROM customers WHERE id = ? AND deleted = 0', [$id]);
    }

    public static function find(string $id): array
    {
        return self::get($id) ?? throw new HttpError(404);
    }

    public static function byPhone(string $phone): ?array
    {
        $n = phone_norm($phone);
        return strlen($n) < 7 ? null : Db::row('SELECT * FROM customers WHERE phone_norm = ? AND deleted = 0 ORDER BY updated_at DESC LIMIT 1', [$n]);
    }

    /** Search by name, company or phone (the payment "Cari" picker and the customer picker of a bill). */
    public static function search(string $q, int $limit = 8, bool $creditOnly = false): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        [$where, $p] = self::match($q);
        return Db::rows("SELECT c.id, c.name, c.phone, c.company, c.credit_enabled, c.credit_limit, c.discount_pct, c.tier_id, c.blacklist, c.loyalty,
                (SELECT COALESCE(SUM(amount), 0) FROM account_ledger a WHERE a.customer_id = c.id) AS balance,
                (SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger l WHERE l.customer_id = c.id) AS points
            FROM customers c WHERE c.deleted = 0 AND $where" . ($creditOnly ? ' AND c.credit_enabled = 1' : '') . " ORDER BY c.name LIMIT $limit", $p);
    }

    /** WHERE part for a name / company / phone search. */
    private static function match(string $q): array
    {
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $p = ['%' . $q . '%', '%' . $q . '%'];
        if (strlen($digits) >= 3) {
            $p[] = '%' . $digits . '%';
        }
        return ['(c.name LIKE ? OR c.company LIKE ?' . (strlen($digits) >= 3 ? ' OR c.phone_norm LIKE ?' : '') . ')', $p];
    }

    /** Orders count, last order time and account balance ("23 sipariş · son: 3 gün önce · cari ₺0"). */
    public static function stats(string $id): array
    {
        $r = Db::row("SELECT COUNT(*) AS n, MAX(opened_at) AS last FROM orders WHERE customer_id = ? AND status = 'paid' AND deleted = 0", [$id]);
        return ['orders' => (int) $r['n'], 'last' => $r['last'] ? (int) $r['last'] : null, 'balance' => self::balance($id)];
    }

    public static function addresses(string $id): array
    {
        return Db::rows('SELECT * FROM customer_addresses WHERE customer_id = ? AND deleted = 0 ORDER BY is_default DESC, updated_at DESC', [$id]);
    }

    /** Creates or updates a customer from the till (name and phone are enough). */
    public static function quickSave(string $name, string $phone, ?string $id = null): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ValidationError(['name' => I18n::t('deliv.err_name')]);
        }
        $existing = $id ? self::get($id) : self::byPhone($phone);
        $row = ['name' => mb_substr($name, 0, 120), 'phone' => trim($phone) ?: null, 'phone_norm' => phone_norm($phone) ?: null];
        if ($existing) {
            Db::save('customers', ['id' => $existing['id']] + $row);
            return $existing['id'];
        }
        $cid = Db::save('customers', $row + ['created_at' => Clock::ms()]);
        Audit::log('customer.create', $row['name'] . ($row['phone'] ? ' · ' . $row['phone'] : ''), 'customer', $cid);
        Loyalty::refreshTier($cid);
        return $cid;
    }

    public static function addAddress(string $customerId, string $address, string $label = '', string $note = ''): string
    {
        $address = trim($address);
        if ($address === '') {
            throw new ValidationError(['address' => I18n::t('deliv.err_address')]);
        }
        $first = !Db::value('SELECT 1 FROM customer_addresses WHERE customer_id = ? AND deleted = 0', [$customerId]);
        return Db::save('customer_addresses', ['customer_id' => $customerId, 'label' => mb_substr(trim($label), 0, 40) ?: null,
            'address' => mb_substr($address, 0, 300), 'note' => mb_substr(trim($note), 0, 200) ?: null, 'is_default' => $first ? 1 : 0]);
    }

    // ------------------------------------------------------------ list (CU1 / CU3)

    /**
     * Customers with their paid bills, last visit, account balance and tag, most recent visit first.
     * $f: q, filter (debt | regular | online | blacklist).
     */
    public static function list(array $f = []): array
    {
        $where = 'c.deleted = 0';
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            [$w, $p] = self::match($q);
            $where .= ' AND ' . $w;
        }
        $rows = Db::rows("SELECT c.*,
                (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status = 'paid' AND o.deleted = 0) AS orders_n,
                (SELECT MAX(COALESCE(o.closed_at, o.opened_at)) FROM orders o WHERE o.customer_id = c.id AND o.status = 'paid' AND o.deleted = 0) AS last_at,
                (SELECT COALESCE(SUM(amount), 0) FROM account_ledger a WHERE a.customer_id = c.id) AS balance,
                (SELECT 1 FROM online_accounts oa WHERE oa.customer_id = c.id AND oa.deleted = 0 LIMIT 1) AS online
            FROM customers c WHERE $where ORDER BY last_at IS NULL, last_at DESC, c.name", $p);
        $out = [];
        foreach ($rows as $r) {
            $r['orders_n'] = (int) $r['orders_n'];
            $r['balance'] = (int) $r['balance'];
            $r['last_at'] = $r['last_at'] ? (int) $r['last_at'] : null;
            $r['tag'] = self::tag($r);
            $keep = match ($f['filter'] ?? '') {
                'debt' => $r['balance'] > 0,
                'regular' => $r['orders_n'] >= self::REGULAR_ORDERS,
                'online' => (bool) $r['online'],
                'blacklist' => (bool) $r['blacklist'],
                default => true,
            };
            if ($keep) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** One tag per customer, as on the list: black list, account, online, regular. */
    public static function tag(array $r): string
    {
        return match (true) {
            (bool) ($r['blacklist'] ?? 0) => 'blacklist',
            (bool) ($r['credit_enabled'] ?? 0) => 'credit',
            (bool) ($r['online'] ?? 0) => 'online',
            (int) ($r['orders_n'] ?? 0) >= self::REGULAR_ORDERS => 'regular',
            default => '',
        };
    }

    /** Head figures and the filter counts of the list. */
    public static function summary(array $all): array
    {
        $s = ['all' => count($all), 'debt' => 0, 'regular' => 0, 'online' => 0, 'blacklist' => 0, 'credit' => 0, 'receivable' => 0];
        foreach ($all as $r) {
            $s['debt'] += $r['balance'] > 0 ? 1 : 0;
            $s['receivable'] += max(0, $r['balance']);
            $s['regular'] += $r['orders_n'] >= self::REGULAR_ORDERS ? 1 : 0;
            $s['online'] += $r['online'] ? 1 : 0;
            $s['blacklist'] += $r['blacklist'] ? 1 : 0;
            $s['credit'] += $r['credit_enabled'] ? 1 : 0;
        }
        return $s;
    }

    /** "bugün", "dün", "3 gün önce", "2 hafta", "3 ay" (last visit). */
    public static function ago(?int $ms): string
    {
        if (!$ms) {
            return '—';
        }
        $days = (int) round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', intdiv($ms, 1000)))) / 86400);
        return match (true) {
            $days <= 0 => t('cust.ago_today'),
            $days === 1 => t('cust.ago_yesterday'),
            $days < 7 => t('cust.ago_days', ['n' => digits($days)]),
            $days < 30 => t('cust.ago_weeks', ['n' => digits(intdiv($days, 7))]),
            $days < 365 => t('cust.ago_months', ['n' => digits(max(1, intdiv($days, 30)))]),
            default => t('cust.ago_years', ['n' => digits(intdiv($days, 365))]),
        };
    }

    // ------------------------------------------------------------ edit (CU6)

    /**
     * Saves the customer form. Name and phone are required and the phone must not belong to someone else.
     * The account (credit) settings, the own discount and the black list need customers.manage.
     */
    public static function save(array $in, ?string $id = null): string
    {
        $old = $id ? self::find($id) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $err = [];
        if ($name === '') {
            $err['name'] = I18n::t('cust.err_name');
        }
        if (strlen(phone_norm($phone)) < 7) {
            $err['phone'] = I18n::t('cust.err_phone');
        } elseif ($dup = Db::row('SELECT id, name FROM customers WHERE phone_norm = ? AND deleted = 0 AND id <> ?', [phone_norm($phone), $id ?? ''])) {
            $err['phone'] = I18n::t('cust.err_phone_taken', ['name' => $dup['name']]);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err['email'] = I18n::t('cust.err_email');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        $row = [
            'name' => mb_substr($name, 0, 120), 'phone' => mb_substr($phone, 0, 40), 'phone_norm' => phone_norm($phone),
            'email' => $email !== '' ? mb_strtolower($email, 'UTF-8') : null,
            'loyalty' => !empty($in['loyalty']) ? 1 : 0,
        ];
        foreach (['company' => 120, 'tax_no' => 40, 'note' => 300] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $row[$k] = mb_substr(trim((string) $in[$k]), 0, $max) ?: null;
            }
        }
        if (Auth::can('customers.manage')) {
            $row['credit_enabled'] = !empty($in['credit']) ? 1 : 0;
            if (array_key_exists('credit_limit', $in)) {
                $row['credit_limit'] = max(0, Money::parse((string) $in['credit_limit']));
            }
            if (array_key_exists('discount_pct', $in)) {
                $row['discount_pct'] = max(0, min(100, read_num($in['discount_pct'], 'discount_pct')));
            }
            if (array_key_exists('blacklist', $in) || $old) {
                $row['blacklist'] = !empty($in['blacklist']) ? 1 : 0;
            }
        }
        $cid = Db::tx(static function () use ($row, $old, $in): string {
            $cid = Db::save('customers', ($old ? ['id' => $old['id']] : ['created_at' => Clock::ms()]) + $row);
            $address = trim((string) ($in['address'] ?? ''));
            if ($address !== '') {
                $def = Db::row('SELECT id, address FROM customer_addresses WHERE customer_id = ? AND deleted = 0 ORDER BY is_default DESC, updated_at DESC LIMIT 1', [$cid]);
                if (!$def) {
                    self::addAddress($cid, $address);
                } elseif ($def['address'] !== $address) {
                    Db::save('customer_addresses', ['id' => $def['id'], 'address' => mb_substr($address, 0, 300)]);
                }
            }
            return $cid;
        });
        if ($old) {
            $changed = array_keys(array_filter($row, static fn($v, string $k): bool => $k !== 'phone_norm' && (string) ($old[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                Audit::log('customer.edit', $row['name'] . ' · ' . implode(', ', $changed), 'customer', $cid);
            }
        } else {
            Audit::log('customer.create', $row['name'] . ' · ' . $row['phone'], 'customer', $cid);
        }
        Loyalty::refreshTier($cid);
        return $cid;
    }

    /** Removes a customer (the history stays); not while the account has a balance. */
    public static function delete(string $id): void
    {
        $c = self::find($id);
        if (self::balance($id) !== 0) {
            throw new \InvalidArgumentException(I18n::t('cust.err_delete_balance'));
        }
        Db::softDelete('customers', $id);
        Audit::log('customer.delete', $c['name'] . ($c['phone'] ? ' · ' . $c['phone'] : ''), 'customer', $id);
    }

    // ------------------------------------------------------------ account (CU2 / CU4)

    public static function balance(string $id): int
    {
        return (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE customer_id = ?', [$id]);
    }

    private const LEDGER_SQL = "SELECT a.*, o.no, o.channel, o.guests, t.number AS table_no, u.name AS user_name
        FROM account_ledger a LEFT JOIN orders o ON o.id = a.order_id LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN users u ON u.id = a.user_id";

    /**
     * Account movements of one month (newest first) with the balance after each one,
     * and the balance brought forward. $month = 'Y-m'.
     */
    public static function ledger(string $id, string $month): array
    {
        [$from, $to] = self::monthRange($month);
        $bal = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE customer_id = ? AND at < ?', [$id, $from]);
        $opening = $bal;
        $rows = Db::rows(self::LEDGER_SQL . ' WHERE a.customer_id = ? AND a.at >= ? AND a.at < ? ORDER BY a.at, a.id', [$id, $from, $to]);
        foreach ($rows as &$r) {
            $bal += (int) $r['amount'];
            $r['balance'] = $bal;
            $r['doc'] = self::docText($r);
        }
        unset($r);
        return ['rows' => array_reverse($rows), 'opening' => $opening, 'closing' => $bal];
    }

    /** The latest movements (phone mini ledger). */
    public static function recentMoves(string $id, int $limit = 5): array
    {
        $rows = Db::rows(self::LEDGER_SQL . " WHERE a.customer_id = ? ORDER BY a.at DESC, a.id DESC LIMIT $limit", [$id]);
        foreach ($rows as &$r) {
            $r['doc'] = self::docText($r, true);
        }
        unset($r);
        return $rows;
    }

    /** "Masa 8 · hesap #0131 · 8 kişi", "Paket #0112", "Tahsilat · nakit · Can", "Devir". */
    public static function docText(array $r, bool $short = false): string
    {
        $no = digits(sprintf('%04d', (int) ($r['no'] ?? 0)));
        return match ($r['kind']) {
            'charge' => $r['order_id'] && in_array($r['channel'], ['table', 'qr'], true)
                ? ($short ? t('cust.doc_table_s', ['n' => digits((string) $r['table_no']), 'no' => $no])
                    : t('cust.doc_table', ['n' => digits((string) $r['table_no']), 'no' => $no]) . ((int) $r['guests'] > 1 ? ' · ' . t('cust.doc_guests', ['n' => digits((int) $r['guests'])]) : ''))
                : ($r['order_id'] ? t('cust.doc_pack', ['no' => $no]) : ($r['note'] ?: t('cust.doc_charge'))),
            'payment' => t('cust.doc_pay', ['method' => mb_strtolower(t('cust.m_' . ($r['method'] ?: 'cash')), 'UTF-8')]) . (!$short && $r['user_name'] ? ' · ' . first_name($r['user_name']) : ''),
            'opening' => t('cust.doc_open') . ($r['note'] ? ' (' . $r['note'] . ')' : ''),
            default => (string) ($r['note'] ?: t('cust.doc_adjust')),
        };
    }

    /** [from, to) in ms of a 'Y-m' month. */
    public static function monthRange(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $from = (int) strtotime($month . '-01 00:00:00');
        return [$from * 1000, (int) strtotime('+1 month', $from) * 1000];
    }

    /** Months with account movements (newest first), always with the current one. */
    public static function ledgerMonths(string $id): array
    {
        $months = [date('Y-m')];
        foreach (Db::rows('SELECT at FROM account_ledger WHERE customer_id = ? ORDER BY at DESC', [$id]) as $r) {
            $months[] = date('Y-m', intdiv((int) $r['at'], 1000));
        }
        return array_values(array_unique($months));
    }

    public static function monthLabel(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        return t('cust.month', ['m' => t('date.m' . $m), 'y' => digits($y)]);
    }

    /** This month's spending (paid bills) and the number of bills. */
    public static function monthSpend(string $id): array
    {
        [$from, $to] = self::monthRange(date('Y-m'));
        $r = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM orders WHERE customer_id = ? AND status = 'paid' AND deleted = 0 AND closed_at >= ? AND closed_at < ?", [$id, $from, $to]);
        return ['amount' => (int) $r['s'], 'bills' => (int) $r['n']];
    }

    public static function lastPayment(string $id): ?array
    {
        return Db::row("SELECT * FROM account_ledger WHERE customer_id = ? AND kind = 'payment' ORDER BY at DESC LIMIT 1", [$id]);
    }

    /** The year of the first visit (or of the registration). */
    public static function since(array $c): ?int
    {
        $first = (int) Db::value('SELECT MIN(opened_at) FROM orders WHERE customer_id = ? AND deleted = 0', [$c['id']]);
        $ms = array_filter([$first, (int) ($c['created_at'] ?? 0)]);
        return $ms ? (int) date('Y', intdiv(min($ms), 1000)) : null;
    }

    /** Turkish ablative suffix of a year as read aloud: 2021 → "den", 2025 → "ten", 2026 → "dan", 2030 → "dan". */
    public static function trFrom(int $year): string
    {
        $ones = ['', 'den', 'den', 'ten', 'ten', 'ten', 'dan', 'den', 'den', 'dan'];
        $tens = ['den', 'dan', 'den', 'dan', 'tan', 'den', 'tan', 'ten', 'den', 'dan'];
        return $year % 10 !== 0 ? $ones[$year % 10] : ($year % 100 !== 0 ? $tens[intdiv($year % 100, 10)] : 'den'); // yüz, bin
    }

    // ------------------------------------------------------------ purchase history

    public static function orders(string $id, int $limit = 50): array
    {
        return Db::rows("SELECT o.id, o.no, o.channel, o.guests, o.total, o.discount, o.opened_at, o.closed_at, t.number AS table_no,
                (SELECT COALESCE(SUM(qty), 0) FROM order_items i WHERE i.order_id = o.id AND i.deleted = 0 AND i.status <> 'void') AS items,
                (SELECT GROUP_CONCAT(DISTINCT p.method) FROM payments p WHERE p.order_id = o.id) AS methods
            FROM orders o LEFT JOIN tables t ON t.id = o.table_id
            WHERE o.customer_id = ? AND o.status = 'paid' AND o.deleted = 0 ORDER BY o.closed_at DESC LIMIT $limit", [$id]);
    }

    /** What the customer orders most. */
    public static function favourites(string $id, int $limit = 5): array
    {
        return Db::rows("SELECT i.name, COUNT(DISTINCT i.order_id) AS times, SUM(i.qty) AS qty
            FROM order_items i JOIN orders o ON o.id = i.order_id
            WHERE o.customer_id = ? AND o.status = 'paid' AND o.deleted = 0 AND i.deleted = 0 AND i.status <> 'void'
            GROUP BY i.item_id ORDER BY qty DESC, times DESC LIMIT $limit", [$id]);
    }

    /** All-time totals: bills, spending, average bill. */
    public static function lifetime(string $id): array
    {
        $r = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM orders WHERE customer_id = ? AND status = 'paid' AND deleted = 0", [$id]);
        return ['bills' => (int) $r['n'], 'amount' => (int) $r['s'], 'avg' => (int) $r['n'] > 0 ? intdiv((int) $r['s'], (int) $r['n']) : 0];
    }

    /** CSV of the list ("Dışa aktar"), Excel-friendly (BOM, semicolons). */
    public static function csv(array $rows): string
    {
        $tiers = array_column(Loyalty::tiers(), 'name', 'id');
        return \Sofrexa\Export\Csv::build(['Ad', 'Telefon', 'E-posta', 'Şirket', 'Vergi no', 'Etiket', 'Sipariş', 'Son ziyaret', 'Bakiye', 'Puan', 'Seviye'],
            array_map(static fn(array $r): array => [$r['name'], $r['phone'], $r['email'], $r['company'], $r['tax_no'], $r['tag'] !== '' ? I18n::t('cust.tag.' . $r['tag'], [], 'tr') : '',
                $r['orders_n'], $r['last_at'] ? date('d.m.Y', intdiv($r['last_at'], 1000)) : '',
                // a customer with no tier has no tier_id: null is not a key (PHP 8.5 says so)
                number_format($r['balance'] / 100, 2, ',', '.'), Loyalty::balance($r['id']), $r['tier_id'] !== null ? ($tiers[$r['tier_id']] ?? '') : ''], $rows));
    }
}
