<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * The activity log. Every sensitive action is written here by the server; the table is
 * append-only (database triggers reject UPDATE and DELETE) and there is no edit endpoint.
 */
final class Audit
{
    /** Actions shown as filters in the activity log, grouped by the badge tone they use. */
    public const GROUPS = [
        'void' => ['order.void_item', 'order.void'],
        'discount' => ['order.discount'],
        'price' => ['menu.price', 'menu.bulk_price'],
        'cash' => ['cash.in', 'cash.out', 'cash.nosale', 'cash.open', 'shift.close'],
        'login' => ['auth.login', 'auth.logout', 'auth.pin_failed', 'auth.password_failed'],
        'settings' => ['settings.save', 'backup.create', 'backup.restore', 'user.save', 'user.delete', 'user.reset_pin', 'role.save'],
    ];

    public static function log(string $action, string $summary = '', ?string $entity = null, ?string $entityId = null, array $detail = [], ?array $actor = null): void
    {
        $actor ??= Auth::user();
        Db::append('audit_log', [
            'at' => Clock::ms(),
            'user_id' => $actor['id'] ?? null,
            'user_name' => $actor['name'] ?? null,
            'device' => self::device(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 500),
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** Short device label from the user agent ("Kasa PC", "iPhone", "Android", "TV"). */
    public static function device(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'CLI';
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $named = $_COOKIE['sofrexa_device'] ?? '';
        if ($named !== '') {
            return mb_substr($named, 0, 40);
        }
        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') && str_contains($ua, 'Mobile') => 'Android',
            str_contains($ua, 'SmartTV') || str_contains($ua, 'Tizen') || str_contains($ua, 'Web0S') => 'TV',
            str_contains($ua, 'Windows') => 'Windows PC',
            str_contains($ua, 'Macintosh') => 'Mac',
            default => 'web',
        };
    }

    public static function tone(string $action): string
    {
        return match (true) {
            in_array($action, ['order.void_item', 'order.void', 'auth.pin_failed', 'auth.password_failed', 'backup.restore', 'user.delete'], true) => 'Danger',
            in_array($action, ['cash.nosale', 'cash.out', 'shift.close'], true) => 'Warning',
            in_array($action, ['order.discount', 'loyalty.redeem'], true) => 'Accent',
            str_starts_with($action, 'menu.') || str_starts_with($action, 'settings.') || str_starts_with($action, 'backup.') || str_starts_with($action, 'role.') => 'Info',
            in_array($action, ['cash.in', 'cash.open'], true) => 'Success',
            default => 'Neutral',
        };
    }
}
