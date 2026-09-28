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
        'stock_items', 'recipes', 'recurring_expenses', 'orders', 'order_items', 'shifts', 'qr_sessions', 'password_resets',
        'notifications', 'promotions',
    ];

    /** Append-only replicated tables (id, never updated). */
    public const APPEND = [
        'audit_log', 'price_history', 'payments', 'order_discounts', 'cash_moves', 'fx_rates', 'loyalty_ledger',
        'account_ledger', 'stock_docs', 'stock_moves', 'stock_count_lines', 'time_entries', 'payroll', 'finance_entries',
    ];

    private static bool $muted = false;

    public static function touch(string $table, string $id): void
    {
        if (($table === 'items' || $table === 'categories') && App::isWeb()) {
            \Sofrexa\Integrations\WebsiteMenu::touch();
        }
        if (self::$muted || !self::enabled() || (!in_array($table, self::MUTABLE, true) && !in_array($table, self::APPEND, true))) {
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

    /**
     * The PC records changes only when a web copy is configured (the first sync sends a full snapshot anyway);
     * the web copy always records, because its QR and online orders must reach the PC.
     */
    public static function enabled(): bool
    {
        return App::isWeb() || (string) App::config('sync.remote_url', '') !== '';
    }

    public static function isReplicated(string $table): bool
    {
        return in_array($table, self::MUTABLE, true) || in_array($table, self::APPEND, true);
    }
}
