<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Customers;

use Sofrexa\Core\{Audit, Db, I18n, ValidationError};

/**
 * Customers: phone lookup at the till (C4), quick registration by the cashier, delivery addresses.
 * The phone is stored as typed and normalised (phone_norm) for matching.
 */
final class Customers
{
    public static function get(string $id): ?array
    {
        return Db::row('SELECT * FROM customers WHERE id = ? AND deleted = 0', [$id]);
    }

    public static function byPhone(string $phone): ?array
    {
        $n = phone_norm($phone);
        return strlen($n) < 7 ? null : Db::row('SELECT * FROM customers WHERE phone_norm = ? AND deleted = 0 ORDER BY updated_at DESC LIMIT 1', [$n]);
    }

    /** Search by name or phone (for the payment "Cari" picker and later the customer list). */
    public static function search(string $q, int $limit = 8, bool $creditOnly = false): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $where = '(c.name LIKE ?' . (strlen($digits) >= 3 ? ' OR c.phone_norm LIKE ?' : '') . ')';
        $p = ['%' . $q . '%'];
        if (strlen($digits) >= 3) {
            $p[] = '%' . $digits . '%';
        }
        return Db::rows("SELECT c.id, c.name, c.phone, c.credit_enabled, c.credit_limit, c.discount_pct,
                (SELECT COALESCE(SUM(amount), 0) FROM account_ledger a WHERE a.customer_id = c.id) AS balance
            FROM customers c WHERE c.deleted = 0 AND $where" . ($creditOnly ? ' AND c.credit_enabled = 1' : '') . " ORDER BY c.name LIMIT $limit", $p);
    }

    /** Orders count, last order time and account balance ("23 sipariş · son: 3 gün önce · cari ₺0"). */
    public static function stats(string $id): array
    {
        $r = Db::row("SELECT COUNT(*) AS n, MAX(opened_at) AS last FROM orders WHERE customer_id = ? AND status = 'paid' AND deleted = 0", [$id]);
        return ['orders' => (int) $r['n'], 'last' => $r['last'] ? (int) $r['last'] : null,
            'balance' => (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE customer_id = ?', [$id])];
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
        $cid = Db::save('customers', $row + ['created_at' => \Sofrexa\Core\Clock::ms()]);
        Audit::log('customer.create', $row['name'] . ($row['phone'] ? ' · ' . $row['phone'] : ''), 'customer', $cid);
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
}
