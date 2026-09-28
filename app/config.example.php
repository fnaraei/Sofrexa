<?php
/** Copy to app/config.php and adjust. Only the keys you change are needed (see config.defaults.php). */
return [
    'role' => 'pc',
    'device_name' => 'Kasa PC',
    'debug' => false,
    'base_url' => 'https://pos.example.com',
    'sync' => ['remote_url' => '', 'key' => ''],
    'printers' => [
        'cashier' => ['driver' => 'windows', 'target' => 'POS-80'],
        'kitchen' => ['driver' => 'tcp', 'target' => '192.168.1.60:9100'],
    ],
    'pin_networks' => ['192.168.1.0/24', '127.0.0.1'],
];
