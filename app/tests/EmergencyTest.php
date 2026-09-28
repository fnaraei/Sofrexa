<?php
/** Emergency mode: only on the web copy while the PC is away; the web copy then takes orders and PIN sign-ins. */
declare(strict_types=1);

use Sofrexa\Core\{App, Settings};
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Setup\Seed;
use Sofrexa\Sync\{Emergency, Status};

return [
    'switched on on the web copy while the PC is away, off when the PC is back' => function (): void {
        Seed::base(static fn() => null);
        Settings::set('online.hours', '');
        Settings::set('security.pin_networks', ['192.168.1.0/24']);
        try {
            Emergency::start('203.0.113.7');
            throw new LogicException('started on the PC');
        } catch (\InvalidArgumentException) {
        }
        App::setConfig('role', 'web');
        App::setConfig('sync.key', 'test-key');
        Status::markOk();
        try {
            Emergency::start('203.0.113.7');
            throw new LogicException('started while the PC is in touch');
        } catch (\InvalidArgumentException) {
        }
        \Sofrexa\Core\Db::exec("UPDATE sync_state SET value = '0' WHERE key = 'last_ok'");
        same('offline', QrOrders::availability());
        same('offline', OnlineOrders::availability());
        check(!QrOrders::owner(), 'the PC owns orders normally');
        check(!\Sofrexa\Core\Auth::pinAllowedFrom('203.0.113.7'), 'no PIN from outside');

        Emergency::start('203.0.113.7');
        check(Emergency::on(), 'on');
        same('open', QrOrders::availability(), 'QR stays open');
        same('open', OnlineOrders::availability(), 'online stays open');
        check(QrOrders::owner(), 'the web copy takes orders in');
        check(\Sofrexa\Core\Auth::pinAllowedFrom('203.0.113.7'), 'PIN from the restaurant line');
        check(!\Sofrexa\Core\Auth::pinAllowedFrom('198.51.100.1'), 'not from anywhere else');
        check(\Sofrexa\Core\Auth::pinAllowedFrom('192.168.1.20'), 'the LAN still works');

        Emergency::end('pc');
        check(!Emergency::on(), 'off when the PC is back');
        check(!QrOrders::owner(), 'the PC owns orders again');
    },
];
