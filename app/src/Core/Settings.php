<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Restaurant settings, replicated between the PC and the web copy. Each key is one row
 * (id = key, value = JSON). The restaurant profile (name, logo, contacts, receipt texts)
 * is the only source of tenant data — nothing in the product hard-codes a restaurant.
 */
final class Settings
{
    public const DEFAULTS = [
        'profile.name' => 'Restoran',
        'profile.short_name' => '',
        'profile.mark_svg' => '',
        'profile.legal_name' => '',
        'profile.tax_no' => '',
        'profile.tax_office' => '',
        'profile.phone' => '',
        'profile.email' => '',
        'profile.website' => '',
        'profile.address' => '',
        'profile.hours' => '',
        'profile.logo' => '',
        'profile.tagline' => '',
        'receipt.header' => 'Afiyet olsun!',
        'receipt.footer' => 'Teşekkür ederiz · Thank you',
        'receipt.logo' => true,
        'receipt.powered_by' => true,
        'day.rollover_hour' => 5,
        'currency.accepted' => ['GBP', 'USD', 'EUR'],
        'lang.staff' => ['tr', 'fa', 'en', 'ru'],
        'lang.customer' => ['tr', 'en', 'ru', 'fa'],
        'printer.cashier' => ['name' => 'Kasa / bar', 'driver' => 'file', 'target' => '', 'width' => 80],
        'printer.kitchen' => ['name' => 'Mutfak', 'driver' => 'file', 'target' => '', 'width' => 80],
        'printer.bar_on' => 'cashier',
        'printer.courier_on' => 'cashier',
        'security.idle_lock_minutes' => 0,
        'security.pin_networks' => [],
        'order.late_minutes' => 20,
        'order.void_reasons' => ['Müşteri vazgeçti', 'Yanlış sipariş', 'Ürün bitti', 'Bekleme uzun sürdü'],
        'qr.enabled' => true,
        'qr.require_first_approval' => true,
        'qr.call_waiter' => true,
        'qr.request_bill' => true,
        'qr.eta_minutes' => '15–20',
        'online.enabled' => true,
        'online.delivery' => true,
        'online.pickup' => true,
        'online.min_order' => 50000,
        'online.delivery_fee' => 0,
        'online.eta_minutes' => '35–45',
        'online.hours' => '11:30 – 22:30',
        'online.area' => '',
        'loyalty.enabled' => true,
        'loyalty.point_value' => 100,     // kuruş per point (1 point = ₺1)
        'loyalty.min_redeem' => 100,
        'loyalty.expiry_months' => 12,
        'loyalty.tier_window_months' => 12,
        'loyalty.no_points_on_discounted' => true,
        'loyalty.points_on_delivery' => true,
        'loyalty.no_points_on_account' => true,
        'vat.food' => 10,
        'vat.drinks' => 10,
        'vat.alcohol' => 20,
        'backup.nightly' => true,
        'backup.hour' => 3,
        'backup.usb' => true,
        'backup.usb_path' => '',
        'backup.to_web' => true,
        'backup.keep_days' => 30,
        'finance.categories' => ['rent', 'energy', 'supplies', 'staff', 'maintenance', 'marketing', 'tax', 'accounting', 'other'],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Db::rows('SELECT id, value FROM settings WHERE deleted = 0') as $r) {
                    self::$cache[$r['id']] = json_decode($r['value'], true);
                }
            } catch (\PDOException) {
                // not migrated yet
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Db::save('settings', ['id' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
        self::$cache = null;
    }

    public static function setMany(array $values): void
    {
        Db::tx(static function () use ($values): void {
            foreach ($values as $k => $v) {
                self::set($k, $v);
            }
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
