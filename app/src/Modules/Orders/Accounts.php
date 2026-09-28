<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Audit, Auth, Clock, Db, HttpError, I18n, Money, ValidationError};

/**
 * Customer accounts (paying later, "veresiye"). Charges and payments go to account_ledger (append-only);
 * the balance is their sum. The statement is in Customers::ledger().
 */
final class Accounts
{
    public static function balance(string $customerId): int
    {
        return (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE customer_id = ?', [$customerId]);
    }

    public static function assertCredit(?string $customerId, int $amount): void
    {
        $c = $customerId ? Db::row('SELECT name, credit_enabled, credit_limit FROM customers WHERE id = ? AND deleted = 0', [$customerId]) : null;
        if (!$c || !$c['credit_enabled']) {
            throw new \InvalidArgumentException(I18n::t('order.err_no_account'));
        }
        if ((int) $c['credit_limit'] > 0 && self::balance($customerId) + $amount > (int) $c['credit_limit']) {
            throw new \InvalidArgumentException(I18n::t('order.err_limit', ['limit' => Money::fmt((int) $c['credit_limit'])]));
        }
    }

    /** Debt from an order paid "on account". */
    public static function charge(string $customerId, int $amount, ?string $orderId): void
    {
        Db::append('account_ledger', ['customer_id' => $customerId, 'amount' => $amount, 'kind' => 'charge', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
    }

    /**
     * The customer pays off debt (CU4 "Tahsil et"): cash and card go through the open shift (the drawer / the POS),
     * a bank transfer does not. Returns the new balance.
     */
    public static function settle(string $customerId, int $amount, string $method, string $note = ''): int
    {
        if (!in_array($method, ['cash', 'card', 'transfer'], true)) {
            $method = 'cash';
        }
        if ($amount <= 0) {
            throw new ValidationError(['amount' => I18n::t('cust.err_amount')]);
        }
        $shift = $method === 'transfer' ? null : Shifts::currentId();
        if ($method !== 'transfer' && !$shift) {
            throw new \InvalidArgumentException(I18n::t('order.err_no_shift'));
        }
        $name = (string) Db::value('SELECT name FROM customers WHERE id = ? AND deleted = 0', [$customerId]);
        if ($name === '') {
            throw new HttpError(404);
        }
        $uid = Auth::user()['id'] ?? null;
        Db::tx(static function () use ($customerId, $amount, $method, $note, $shift, $uid): void {
            Db::append('account_ledger', ['customer_id' => $customerId, 'amount' => -$amount, 'kind' => 'payment', 'method' => $method, 'note' => mb_substr(trim($note), 0, 200) ?: null, 'at' => Clock::ms(), 'user_id' => $uid]);
            Db::append('payments', ['order_id' => null, 'shift_id' => $shift, 'method' => $method, 'currency' => 'TRY', 'amount_fx' => 0, 'rate' => 1, 'amount' => $amount, 'change_given' => 0,
                'customer_id' => $customerId, 'at' => Clock::ms(), 'user_id' => $uid, 'note' => 'hesap ödemesi']);
        });
        $balance = self::balance($customerId);
        Audit::log('account.payment', $name . ' · ' . Money::fmt($amount, false, 'tr') . ' · ' . $method . ' · kalan ' . Money::fmt($balance, false, 'tr'), 'customer', $customerId);
        return $balance;
    }
}
