<?php
declare(strict_types=1);

namespace Sofrexa\View;

use Sofrexa\Core\Auth;

/** Navigation for the staff shell: desktop side navigation and the role's mobile tab bar. */
final class Shell
{
    /** key => [label key, icon, url, permission] — order as on the Figma SideNav. */
    public const NAV = [
        'dashboard' => ['nav.dashboard', 'dashboard', '/', 'reports.view'],
        'tables' => ['nav.tables', 'grid', '/tables', 'orders.take'],
        'cashier' => ['nav.cashier', 'receipt', '/cashier', 'cash.pay'],
        'delivery' => ['nav.delivery', 'bike', '/delivery', 'delivery.manage'],
        'kitchen' => ['nav.kitchen', 'chef-hat', '/kitchen', 'kitchen.view'],
        'menu' => ['nav.menu', 'utensils', '/menu', 'menu.manage'],
        'stock' => ['nav.stock', 'box', '/stock', 'stock.manage'],
        'customers' => ['nav.customers', 'users', '/customers', 'customers.view'],
        'staff' => ['nav.staff', 'user', '/staff', 'staff.manage'],
        'reports' => ['nav.reports', 'chart', '/reports', 'reports.view'],
        'finance' => ['nav.finance', 'wallet', '/finance', 'finance.manage'],
        'settings' => ['nav.settings', 'settings', '/settings', 'settings.manage'],
    ];

    /** Mobile tab bars per role (Figma W1, C8, R1). */
    public const TABS = [
        'waiter' => [
            'tables' => ['tab.tables', 'grid', '/tables'],
            'orders' => ['tab.orders', 'receipt', '/my/orders'],
            'notifications' => ['tab.notifications', 'bell', '/my/notifications'],
            'profile' => ['tab.profile', 'user', '/my'],
        ],
        'cashier' => [
            'cashier' => ['tab.cashier', 'receipt', '/cashier'],
            'tables' => ['tab.tables', 'grid', '/tables'],
            'delivery' => ['tab.delivery', 'bike', '/delivery'],
            'more' => ['tab.more_long', 'menu', '/more'],
        ],
        'manager' => [
            'dashboard' => ['tab.dashboard', 'dashboard', '/'],
            'menu' => ['tab.menu', 'utensils', '/menu'],
            'stock' => ['tab.stock', 'box', '/stock'],
            'reports' => ['tab.reports', 'chart', '/reports'],
            'more' => ['tab.more', 'menu', '/more'],
        ],
    ];

    public static function navItems(): array
    {
        $out = [];
        foreach (self::NAV as $key => [$label, $icon, $url, $perm]) {
            if (Auth::can($perm)) {
                $out[$key] = [t($label), $icon, $url];
            }
        }
        return $out;
    }

    public static function tabSet(): ?array
    {
        $u = Auth::user();
        if (!$u) {
            return null;
        }
        $role = $u['role_code'];
        if ($role === 'manager' || Auth::can('reports.view')) {
            return self::TABS['manager'];
        }
        if ($role === 'cashier' || Auth::can('cash.pay')) {
            return self::TABS['cashier'];
        }
        if ($role === 'waiter' || Auth::can('orders.take')) {
            return self::TABS['waiter'];
        }
        return null;
    }

    /** Where a user lands after login. */
    public static function home(?array $u = null): string
    {
        $u ??= Auth::user();
        if (!$u) {
            return '/login';
        }
        if (Auth::can('reports.view')) {
            return '/';
        }
        if (Auth::can('cash.pay')) {
            return '/cashier';
        }
        if (Auth::can('orders.take')) {
            return '/tables';
        }
        if (Auth::can('kitchen.view')) {
            return '/kitchen';
        }
        if (Auth::can('stock.manage')) {
            return '/stock';
        }
        return '/my';
    }
}
