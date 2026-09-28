<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Clock, Db, I18n};

/**
 * Staff notifications (W6): food ready, QR order to approve, bill requested, waiter called.
 * A notification goes to one user (the table's waiter) or to a role. Texts are rendered in the
 * reader's language from 'kind' and the parameters kept in 'body' (JSON).
 */
final class Notify
{
    public const KINDS = ['ready', 'qr', 'bill', 'call', 'served', 'online', 'void', 'printer'];

    /** Kinds the phone rings about (W12): plates going cold, and a guest order nobody has looked at. */
    public const ALERT_KINDS = ['ready', 'qr'];

    /**
     * A notification about one order line (a cancelled dish the till decides on). It is not closed with its bill:
     * the bill may be paid long before the kitchen answers.
     */
    public static function pushLine(string $kind, array $params, string $role, string $lineId): string
    {
        return Db::save('notifications', ['user_id' => null, 'role' => $role, 'kind' => $kind, 'title' => $params['where'] ?? null,
            'body' => $params, 'ref_type' => 'order_item', 'ref_id' => $lineId, 'at' => Clock::ms()]);
    }

    /** Closes the open notifications of an order line. */
    public static function closeLine(string $lineId): void
    {
        foreach (Db::rows("SELECT id FROM notifications WHERE ref_type = 'order_item' AND ref_id = ? AND done_at IS NULL AND deleted = 0", [$lineId]) as $n) {
            self::done($n['id']);
        }
    }

    public static function push(string $kind, array $params, ?string $userId = null, ?string $role = null, ?string $orderId = null): string
    {
        // one open notification per order and kind is enough (a second "bill" tap refreshes it)
        if ($orderId && ($old = Db::row('SELECT id, body FROM notifications WHERE ref_type = ? AND ref_id = ? AND kind = ? AND done_at IS NULL AND deleted = 0', ['order', $orderId, $kind]))) {
            $prev = json_arr($old['body']);
            if (isset($prev['lines'], $params['lines'])) {
                // more plates of the same table: one alert listing all of them
                $params['lines'] = array_values(array_unique(array_merge((array) $prev['lines'], (array) $params['lines'])));
                $params['what'] = trim(($prev['what'] ?? '') . ', ' . ($params['what'] ?? ''), ', ');
                $params['text'] = trim(($prev['text'] ?? '') . ', ' . ($params['text'] ?? ''), ', ');
                $params['items'] = array_merge((array) ($prev['items'] ?? []), (array) ($params['items'] ?? []));
                if (($prev['station'] ?? null) !== ($params['station'] ?? null)) {
                    $params['station'] = null; // the kitchen and the bar both have something: no single badge fits
                }
            }
            Db::save('notifications', ['id' => $old['id'], 'body' => $params, 'at' => Clock::ms(), 'read_at' => null]);
            return $old['id'];
        }
        return Db::save('notifications', ['user_id' => $userId, 'role' => $userId ? null : $role, 'kind' => $kind, 'title' => $params['where'] ?? null,
            'body' => $params, 'ref_type' => $orderId ? 'order' : null, 'ref_id' => $orderId, 'at' => Clock::ms()]);
    }

    /** The table's waiter, or every waiter when nobody owns the table. */
    public static function toWaiter(string $kind, array $order, array $params = []): string
    {
        $params += ['where' => Orders::where($order)];
        return self::push($kind, $params, $order['waiter_id'] ?? null, 'waiter', $order['id']);
    }

    public static function forMe(int $limit = 50): array
    {
        [$where, $p] = self::mine();
        return Db::rows("SELECT * FROM notifications WHERE deleted = 0 AND $where ORDER BY done_at IS NOT NULL, at DESC LIMIT $limit", $p);
    }

    /**
     * Alerts for me that the phone should ring about (W12): open, and not yet looked at. "Sonra" sets read_at,
     * which stops the ringing without handling the alert. Oldest first — the plate that has waited longest wins.
     */
    public static function alerts(int $limit = 5): array
    {
        [$where, $p] = self::mine();
        $rows = Db::rows("SELECT id, kind, title, body, at FROM notifications WHERE deleted = 0 AND done_at IS NULL
            AND read_at IS NULL AND kind IN (" . Db::in(self::ALERT_KINDS) . ") AND $where ORDER BY at LIMIT $limit", [...self::ALERT_KINDS, ...$p]);
        return array_map(static function (array $n): array {
            $b = json_arr($n['body']);
            $station = $b['station'] ?? null;
            return ['id' => $n['id'], 'kind' => $n['kind'], 'where' => (string) ($b['where'] ?? $n['title'] ?? ''),
                'area' => $b['area'] ?? '' ? tn($b['area']) : '',
                'station' => $station ? I18n::t('kds.st_' . $station) : '',
                'items' => array_values(array_filter((array) ($b['items'] ?? []), 'is_array')),
                'what' => (string) ($b['what'] ?? ''), 'text' => (string) ($b['text'] ?? ''), 'at' => (int) $n['at']];
        }, $rows);
    }

    public static function unread(): int
    {
        [$where, $p] = self::mine();
        return (int) Db::value("SELECT COUNT(*) FROM notifications WHERE deleted = 0 AND read_at IS NULL AND done_at IS NULL AND $where", $p);
    }

    /** Notifications for me: sent to me, or to a role whose work I do (a cashier also hears the waiters' calls). */
    private static function mine(): array
    {
        $u = Auth::user();
        $roles = [$u['role_code']];
        if (Auth::can('orders.take')) {
            $roles[] = 'waiter';
        }
        if (Auth::can('cash.pay')) {
            $roles[] = 'cashier';
        }
        $roles = array_values(array_unique($roles));
        return ['(user_id = ? OR (user_id IS NULL AND role IN (' . Db::in($roles) . ')))', [$u['id'], ...$roles]];
    }

    /** Whether a notification is addressed to the signed-in user (to them, or to a role whose work they do). */
    public static function isMine(array $n): bool
    {
        [$where, $p] = self::mine();
        return (bool) Db::value("SELECT 1 FROM notifications WHERE id = ? AND $where", [$n['id'], ...$p]);
    }

    public static function markAllRead(): void
    {
        [$where, $p] = self::mine();
        foreach (Db::rows("SELECT id FROM notifications WHERE deleted = 0 AND read_at IS NULL AND $where", $p) as $n) {
            Db::save('notifications', ['id' => $n['id'], 'read_at' => Clock::ms()]);
        }
    }

    /** "Aldım" / "Gidiyorum": handled, drops to the earlier list. */
    public static function done(string $id): void
    {
        Db::save('notifications', ['id' => $id, 'read_at' => Clock::ms(), 'done_at' => Clock::ms()]);
    }

    /** "Sonra": seen, stays open. */
    public static function later(string $id): void
    {
        Db::save('notifications', ['id' => $id, 'read_at' => Clock::ms()]);
    }

    /** Closes the open notifications of an order (paid, moved, handled at the till). */
    public static function closeFor(string $orderId, ?string $kind = null): void
    {
        $rows = Db::rows('SELECT id FROM notifications WHERE ref_type = ? AND ref_id = ? AND done_at IS NULL AND deleted = 0' . ($kind ? ' AND kind = ?' : ''), $kind ? ['order', $orderId, $kind] : ['order', $orderId]);
        foreach ($rows as $n) {
            self::done($n['id']);
        }
    }
}
