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
        // (the "food is ready" alert is not made here: Kitchen::announce builds it from the plates themselves)
        if ($orderId && ($old = Db::row('SELECT id, body FROM notifications WHERE ref_type = ? AND ref_id = ? AND kind = ? AND done_at IS NULL AND deleted = 0', ['order', $orderId, $kind]))) {
            // the bill may have changed hands since the first alert: it goes to whoever looks after the table now
            Db::save('notifications', ['id' => $old['id'], 'body' => $params, 'at' => Clock::ms(), 'read_at' => null,
                'user_id' => $userId, 'role' => $userId ? null : $role]);
            return $old['id'];
        }
        return Db::save('notifications', ['user_id' => $userId, 'role' => $userId ? null : $role, 'kind' => $kind, 'title' => $params['where'] ?? null,
            'body' => $params, 'ref_type' => $orderId ? 'order' : null, 'ref_id' => $orderId, 'at' => Clock::ms()]);
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
        return Db::tx(static function () use ($n, $seen): array {
            // read the alert again inside the lock: a plate the kitchen put on it since the caller read it must stay
            $n = Db::row('SELECT * FROM notifications WHERE id = ? AND deleted = 0', [$n['id']]);
            if (!$n || $n['done_at']) {
                return [];
            }
            $b = json_arr($n['body']);
            $all = array_map('strval', (array) ($b['lines'] ?? []));
            $took = $seen === null ? $all : array_values(array_intersect($all, array_map('strval', $seen)));
            $left = array_values(array_diff($all, $took));
            if (!$left) {
                self::done($n['id']);
                return $took;
            }
            self::saveLines($n['id'], $b, $left, null);
            return $took;
        });
    }

    /** A ready alert's body cut down to $lines (with their plate rows); $readAt null makes it ring again. */
    private static function saveLines(string $id, array $b, array $lines, int|string|null $readAt): void
    {
        $b['lines'] = $lines;
        $b['items'] = array_values(array_filter((array) ($b['items'] ?? []), static fn($i): bool => is_array($i) && in_array((string) ($i['id'] ?? ''), $lines, true)));
        Db::save('notifications', ['id' => $id, 'body' => self::describe($b), 'read_at' => $readAt]);
    }

    /**
     * Someone else looks after an order now (a waiter change): its open alerts for the waiter follow the bill, so the
     * new waiter's phone rings for the plates and the old one's stops. What the till was asked (the bill printed, a
     * take-away bag at the pass) stays with the till.
     */
    public static function follow(string $orderId, ?string $userId): void
    {
        $userId = self::recipient($userId);
        foreach (Db::rows("SELECT id FROM notifications WHERE ref_type = 'order' AND ref_id = ? AND done_at IS NULL AND deleted = 0
            AND kind IN ('ready', 'qr', 'bill', 'call', 'served') AND (user_id IS NOT NULL OR role = 'waiter')", [$orderId]) as $n) {
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

    /** The table's waiter, or every waiter when nobody owns the table or its waiter has gone home. */
    public static function toWaiter(string $kind, array $order, array $params = []): string
    {
        $params += ['where' => Orders::where($order)];
        return self::push($kind, $params, self::recipient($order['waiter_id'] ?? null), 'waiter', $order['id']);
    }

    /**
     * Who hears an alert for a table: its waiter while on shift. One who has clocked out is not called back — the alert
     * goes to every waiter, and the bill keeps its waiter (their sales stay theirs).
     */
    public static function recipient(?string $waiterId): ?string
    {
        return $waiterId && !\Sofrexa\Modules\Staff\Staff::offShift($waiterId) ? $waiterId : null;
    }

    public static function forMe(int $limit = 50): array
    {
        [$where, $p] = self::mine();
        $rows = Db::rows("SELECT * FROM notifications WHERE deleted = 0 AND $where ORDER BY done_at IS NOT NULL, at DESC LIMIT $limit", $p);
        foreach ($rows as &$n) {
            $n['place'] = self::place($n); // where the bill is now, not where it was when the alert was made
        }
        unset($n);
        return $rows;
    }

    /**
     * Where an alert's bill is now — "Masa 3" and its area — read from the bill itself, never from the copy kept in the
     * alert: a bill moved to another table takes every one of its alerts with it (decision 50). Null for an alert that
     * is not about a bill (a printer, say), which keeps the text it was made with.
     * @return array{where:string, area:string}|null
     */
    public static function place(array $n): ?array
    {
        $orderId = match ($n['ref_type'] ?? null) {
            'order' => $n['ref_id'],
            'order_item' => Db::value('SELECT order_id FROM order_items WHERE id = ?', [$n['ref_id']]),
            default => null,
        };
        if (!$orderId) {
            return null;
        }
        $o = Db::row('SELECT o.id, o.channel, o.no, o.label, o.table_id, t.number AS table_no, a.name AS area_name, a.names AS area_names
            FROM orders o LEFT JOIN tables t ON t.id = o.table_id LEFT JOIN areas a ON a.id = t.area_id WHERE o.id = ?', [$orderId]);
        if (!$o) {
            return null;
        }
        return ['where' => Orders::where($o), 'area' => $o['area_name'] ? tn(json_arr((string) $o['area_names']) ?: $o['area_name']) : ''];
    }

    /**
     * Alerts for me that the phone should ring about (W12): open, and not yet looked at. "Sonra" sets read_at,
     * which stops the ringing without handling the alert. Oldest first — the plate that has waited longest wins.
     */
    public static function alerts(int $limit = 5): array
    {
        // someone who has clocked out is not rung for anything, not even for alerts every waiter hears
        if (\Sofrexa\Modules\Staff\Staff::offShift((string) (Auth::user()['id'] ?? ''))) {
            return [];
        }
        [$where, $p] = self::mine();
        // a guest order to approve rings only for someone who may approve it
        $kinds = Auth::can('orders.qr_approve') ? self::ALERT_KINDS : array_values(array_diff(self::ALERT_KINDS, ['qr']));
        $rows = Db::rows("SELECT id, kind, title, body, at, ref_type, ref_id FROM notifications WHERE deleted = 0 AND done_at IS NULL
            AND read_at IS NULL AND kind IN (" . Db::in($kinds) . ") AND $where ORDER BY at LIMIT $limit", [...$kinds, ...$p]);
        return array_map(static function (array $n): array {
            $b = json_arr($n['body']);
            $station = $b['station'] ?? null;
            $place = self::place($n) ?? ['where' => (string) ($b['where'] ?? $n['title'] ?? ''), 'area' => !empty($b['area']) ? tn($b['area']) : ''];
            return ['id' => $n['id'], 'kind' => $n['kind'], 'where' => $place['where'], 'area' => $place['area'],
                'station' => $station ? I18n::t('kds.st_' . $station) : '',
                'items' => array_values(array_filter((array) ($b['items'] ?? []), 'is_array')),
                // the plates on the card, and a version: the same alert with a new plate on it — or its bill moved to
                // another table — is drawn again, and "Aldım" names the plates that were on the screen
                'lines' => array_values(array_map('strval', (array) ($b['lines'] ?? []))),
                'rev' => substr(md5($n['body'] . '|' . $place['where'] . '|' . $place['area']), 0, 12),
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

    /** Closes the open notifications of an order (of one kind, or all but $except). A bill that ends goes through Orders::ended. */
    public static function closeFor(string $orderId, ?string $kind = null, array $except = []): void
    {
        $rows = Db::rows('SELECT id FROM notifications WHERE ref_type = ? AND ref_id = ? AND done_at IS NULL AND deleted = 0'
            . ($kind ? ' AND kind = ?' : '') . ($except ? ' AND kind NOT IN (' . Db::in($except) . ')' : ''),
            ['order', $orderId, ...($kind ? [$kind] : []), ...$except]);
        foreach ($rows as $n) {
            self::done($n['id']);
        }
    }
}
