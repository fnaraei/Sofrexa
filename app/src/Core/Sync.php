<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Change tracking for replication between the PC and the web copy.
 *
 * Every write to a replicated table goes through Db::save()/softDelete()/append(), which calls
 * touch(). The sync client ships the touched rows; the receiving side upserts them with
 * last-write-wins on updated_at (mutable tables) or insert-if-missing (append-only tables).
 */
final class Sync
{
    /** Mutable replicated tables (id + updated_at + deleted). */
    public const MUTABLE = [
        'settings', 'roles', 'users', 'areas', 'tables', 'categories', 'items', 'modifier_groups', 'modifiers',
        'item_modifier_groups', 'tiers', 'customers', 'customer_addresses', 'online_accounts', 'suppliers',
        'stock_items', 'recipes', 'recurring_expenses', 'orders', 'order_items', 'shifts', 'qr_sessions',
    ];

    /** Append-only replicated tables (id, never updated). */
    public const APPEND = [
        'audit_log', 'price_history', 'payments', 'order_discounts', 'cash_moves', 'fx_rates', 'loyalty_ledger',
        'account_ledger', 'stock_docs', 'stock_moves', 'stock_count_lines', 'time_entries', 'payroll', 'finance_entries',
    ];

    private static bool $muted = false;

    public static function touch(string $table, string $id): void
    {
        if (self::$muted || (!in_array($table, self::MUTABLE, true) && !in_array($table, self::APPEND, true))) {
            return;
        }
        Db::exec('INSERT INTO sync_outbox (tbl, row_id, at) VALUES (?, ?, ?)', [$table, $id, Clock::ms()]);
    }

    /** Run $fn without recording changes (used when applying rows received from the other side). */
    public static function muted(callable $fn): mixed
    {
        $was = self::$muted;
        self::$muted = true;
        try {
            return $fn();
        } finally {
            self::$muted = $was;
        }
    }

    public static function isReplicated(string $table): bool
    {
        return in_array($table, self::MUTABLE, true) || in_array($table, self::APPEND, true);
    }
}
