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
                $params = self::withPlates($prev, $params);
            }
            // the bill may have changed hands since the first alert: it goes to whoever looks after the table now
            Db::save('notifications', ['id' => $old['id'], 'body' => $params, 'at' => Clock::ms(), 'read_at' => null,
                'user_id' => $userId, 'role' => $userId ? null : $role]);
            return $old['id'];
        }
        return Db::save('notifications', ['user_id' => $userId, 'role' => $userId ? null : $role, 'kind' => $kind, 'title' => $params['where'] ?? null,
            'body' => $params, 'ref_type' => $orderId ? 'order' : null, 'ref_id' => $orderId, 'at' => Clock::ms()]);
    }

    /**
     * More plates for an open "ready" alert: one alert listing all of them, one row per order line. Calling the waiter
     * again for the same plates changes nothing, and every row keeps its line, so "Aldım" can say exactly what was seen.
     */
    private static function withPlates(array $prev, array $params): array
    {
        $rows = [];
        foreach ([...(array) ($prev['items'] ?? []), ...(array) ($params['items'] ?? [])] as $i) {
            if (is_array($i)) {
                $rows[(string) ($i['id'] ?? 'row' . count($rows))] = $i;
            }
        }
        $params['lines'] = array_values(array_unique(array_merge((array) $prev['lines'], (array) $params['lines'])));
        $params['items'] = array_values($rows);
        if (($prev['station'] ?? null) !== ($params['station'] ?? null)) {
            $params['station'] = null; // the kitchen and the bar both have something: no single badge fits
        }
        return self::describe($params);
    }

    /** "what" and "text" of a ready alert, rebuilt from its plate rows (the notifications list reads them). */
    public static function describe(array $b): array
    {
        if (!empty($b['items'])) {
            $b['what'] = implode(', ', array_map(static fn(array $i): string => $i['name'] . ((string) ($i['qty'] ?? '1') !== '1' ? ' ×' . $i['qty'] : ''), $b['items']));
            $b['text'] = $b['what'] . (!empty($b['station']) ? ' · ' . ($b['station'] === 'bar' ? 'bar' : 'mutfak') : '');
        }
        return $b;
    }

    /**
     * "Aldım" on a ready alert, for the plates the phone showed ($seen; every plate on it when the client did not say).
     * Plates that joined the alert after it was shown stay on it, and it rings again for them. Returns the lines taken.
     */
    public static function collect(array $n, ?array $seen): array
    {
        $b = json_arr($n['body']);
        $all = array_map('strval', (array) ($b['lines'] ?? []));
        $took = $seen === null ? $all : array_values(array_intersect($all, array_map('strval', $seen)));
        $left = array_values(array_diff($all, $took));
        if (!$left) {
            self::done($n['id']);
            return $took;
        }
        $b['lines'] = $left;
        $b['items'] = array_values(array_filter((array) ($b['items'] ?? []), static fn($i): bool => is_array($i) && in_array((string) ($i['id'] ?? ''), $left, true)));
        Db::save('notifications', ['id' => $n['id'], 'body' => self::describe($b), 'read_at' => null]);
        return $took;
    }

    /**
     * Someone else looks after an order now (a waiter change): its open alerts follow the bill, so the new waiter's
     * phone rings for the plates and the old one's stops.
     */
    public static function follow(string $orderId, ?string $userId): void
    {
        foreach (Db::rows("SELECT id FROM notifications WHERE ref_type = 'order' AND ref_id = ? AND done_at IS NULL AND deleted = 0
            AND kind IN ('ready', 'qr', 'bill', 'call', 'served')", [$orderId]) as $n) {
            Db::save('notifications', ['id' => $n['id'], 'user_id' => $userId, 'role' => $userId ? null : 'waiter', 'read_at' => null]);
        }
    }

    /**
     * A waiter left their shift: the alerts addressed to them go to every waiter, so a plate waiting at the pass does
     * not ring on a phone that has gone home.
     */
    public static function release(string $userId): void
    {
        foreach (Db::rows("SELECT id FROM notifications WHERE user_id = ? AND done_at IS NULL AND deleted = 0 AND kind IN ('ready', 'qr', 'bill', 'call')", [$userId]) as $n) {
            Db::save('notifications', ['id' => $n['id'], 'user_id' => null, 'role' => 'waiter', 'read_at' => null]);
        }
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
        // a guest order to approve rings only for someone who may approve it
        $kinds = Auth::can('orders.qr_approve') ? self::ALERT_KINDS : array_values(array_diff(self::ALERT_KINDS, ['qr']));
        $rows = Db::rows("SELECT id, kind, title, body, at FROM notifications WHERE deleted = 0 AND done_at IS NULL
            AND read_at IS NULL AND kind IN (" . Db::in($kinds) . ") AND $where ORDER BY at LIMIT $limit", [...$kinds, ...$p]);
        return array_map(static function (array $n): array {
            $b = json_arr($n['body']);
            $station = $b['station'] ?? null;
            return ['id' => $n['id'], 'kind' => $n['kind'], 'where' => (string) ($b['where'] ?? $n['title'] ?? ''),
                'area' => !empty($b['area']) ? tn($b['area']) : '',
                'station' => $station ? I18n::t('kds.st_' . $station) : '',
                'items' => array_values(array_filter((array) ($b['items'] ?? []), 'is_array')),
                // the plates on the card, and a version: the same alert with a new plate on it is shown again, and
                // "Aldım" names the plates that were on the screen
                'lines' => array_values(array_map('strval', (array) ($b['lines'] ?? []))), 'rev' => substr(md5((string) $n['body']), 0, 12),
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
