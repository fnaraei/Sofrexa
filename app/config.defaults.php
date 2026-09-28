<?php
/**
 * Default configuration. Override any key in app/config.php (not in git), or point the
 * SOFREXA_CONFIG environment variable at another file (used by tests and the second
 * "web copy" instance during development).
 */
return [
    // 'pc' = the in-house server on the cashier PC (owner of orders, money and stock),
    // 'web' = the synced copy on the host that receives QR and online orders.
    'role' => 'pc',
    'device_name' => 'Kasa PC',
    'timezone' => 'Asia/Famagusta',
    'storage' => ROOT_DIR . '/storage',
    'db' => ROOT_DIR . '/storage/db/sofrexa.sqlite',
    'debug' => false,
    'base_url' => '',          // e.g. https://pos.basiliccaferestaurant.com (used in e-mails and QR links)
    'session_name' => 'sofrexa',
    'session_days' => 14,

    // Sync between the PC and the web copy (see Sync\Client / Sync\Server).
    'sync' => [
        'remote_url' => '',    // on the PC: the web copy's base URL
        'key' => '',           // shared secret, identical on both sides
        'interval' => 3,       // seconds between sync rounds on the PC
        'heartbeat_timeout' => 60, // web closes QR/online ordering if the PC is silent this long
    ],

    // Printers: driver = file | windows | share | tcp  (see Print\Printer)
    'printers' => [
        'cashier' => ['driver' => 'file', 'target' => ''],
        'kitchen' => ['driver' => 'file', 'target' => ''],
    ],

    // Outgoing e-mail for online customers (verification codes, order status).
    'mail' => [
        'driver' => 'log',     // log | mail | smtp
        'from' => 'noreply@example.com',
        'from_name' => 'Sofrexa',
        'smtp' => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
    ],

    // Cloudflare Turnstile for remote (off-site) manager login and online sign-up.
    'turnstile' => ['site_key' => '', 'secret' => ''],

    // Addresses allowed to use PIN login (in-house network). Empty = allow all (development).
    'pin_networks' => [],
];
