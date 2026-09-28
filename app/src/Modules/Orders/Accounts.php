<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Clock, Db, I18n, Money};

/**
 * Customer accounts (paying later, "veresiye"). Charges and payments go to account_ledger (append-only);
 * the balance is their sum. Full statements come with the customers stage.
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

    /** The customer pays off debt. */
    public static function settle(string $customerId, int $amount, string $method, string $note = ''): void
    {
        Db::append('account_ledger', ['customer_id' => $customerId, 'amount' => -$amount, 'kind' => 'payment', 'method' => $method, 'note' => $note ?: null, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        Db::append('payments', ['order_id' => null, 'shift_id' => Shifts::currentId(), 'method' => $method, 'currency' => 'TRY', 'amount_fx' => 0, 'rate' => 1, 'amount' => $amount, 'change_given' => 0,
            'customer_id' => $customerId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null, 'note' => 'hesap ödemesi']);
    }
}
