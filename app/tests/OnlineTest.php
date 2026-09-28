<?php
/** Online ordering: accounts with e-mail codes, the cart and minimum order, acceptance with a ready time, tracking, e-mails. */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Settings};
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\Modules\Online\{Accounts, OnlineOrders};
use Sofrexa\Modules\Orders\{Delivery, Orders, Shifts};
use Sofrexa\Setup\Seed;

$lastCode = static function (): string {
    $files = glob(App::storage('mail') . '/*.eml') ?: [];
    rsort($files); // the names sort by the time they were written
    [, $body] = explode("\r\n\r\n", (string) file_get_contents($files[0]), 2);
    preg_match('/\b(\d{6})\b/', (string) base64_decode(preg_replace('/\s+/', '', $body) ?? ''), $m);
    return $m[1] ?? '';
};
$mails = static fn(): int => count(glob(App::storage('mail') . '/*.eml') ?: []);
$setup = static function (): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$boss]));
    $cat = Db::save('categories', ['names' => ['tr' => 'Çorbalar'], 'station' => 'kitchen']);
    $soup = Menu::saveItem(['names' => ['tr' => 'Borş Çorbası'], 'category_id' => $cat, 'price' => '420', 'available' => 1, 'show_online' => 1]);
    $pizza = Menu::saveItem(['names' => ['tr' => 'Margarita'], 'category_id' => $cat, 'price' => '650', 'available' => 1, 'show_online' => 1]);
    Shifts::open(['TRY' => 0]);
    Settings::set('online.hours', '');
    Auth::actAs(null);
    foreach (glob(App::storage('mail') . '/*.eml') ?: [] as $f) {
        @unlink($f);
    }
    $_SESSION = [];
    return compact('boss', 'soup', 'pizza');
};
$register = static function () use ($lastCode): array {
    $a = Accounts::register(['name' => 'Zeynep Kaya', 'email' => 'Zeynep@Example.com', 'phone' => '0533 604 55 12', 'password' => 'deneme123', 'terms' => true]);
    same('zeynep@example.com', $a['email'], 'e-mail kept in lower case');
    same(null, $a['verified_at']);
    $code = $lastCode();
    same(6, strlen($code), 'code sent by e-mail');
    return [$a, $code];
};

return [
    'sign-up, code, sign-in and a new password' => function () use ($setup, $register, $lastCode, $mails): void {
        $setup();
        foreach ([['name' => '', 'email' => 'x', 'phone' => '1', 'password' => 'short', 'terms' => false]] as $bad) {
            try {
                Accounts::register($bad);
                throw new LogicException('bad sign-up accepted');
            } catch (\Sofrexa\Core\ValidationError $e) {
                same(['name', 'email', 'phone', 'password', 'terms'], array_keys($e->errors));
            }
        }
        [$a, $code] = $register();
        check(!Accounts::passwordOk('abcdefgh') && Accounts::passwordOk('abcdefg1'), 'password rule: 8 characters and a digit');
        try {
            Accounts::sendCode(Accounts::get($a['id']), 'verify');
            throw new LogicException('second code within a minute');
        } catch (\InvalidArgumentException) {
        }
        // an unverified account signs in, but is not signed in until the code
        $x = Accounts::login('zeynep@example.com', 'deneme123', true);
        same(null, $x['verified_at']);
        same(null, Accounts::current());
        try {
            Accounts::verify($a['id'], $code === '000000' ? '111111' : '000000');
            throw new LogicException('wrong code accepted');
        } catch (\Sofrexa\Core\ValidationError) {
        }
        Accounts::verify($a['id'], $code);
        same($a['id'], Accounts::current()['id'], 'signed in after the code');
        try {
            Accounts::register(['name' => 'X', 'email' => 'zeynep@example.com', 'phone' => '05330000000', 'password' => 'deneme123', 'terms' => true]);
            throw new LogicException('second account for the same e-mail');
        } catch (\Sofrexa\Core\ValidationError $e) {
            same(['email'], array_keys($e->errors));
        }
        try {
            Accounts::login('zeynep@example.com', 'wrong-pass1', true);
            throw new LogicException('wrong password accepted');
        } catch (\Sofrexa\Core\ValidationError) {
        }
        // forgotten password: a code, then the new password (twice) signs in
        Accounts::logout();
        Db::exec('UPDATE online_accounts SET code_sent_at = ? WHERE id = ?', [Clock::ms() - 120_000, $a['id']]);
        $before = $mails();
        Accounts::startReset('nobody@example.com');
        same($before, $mails(), 'no mail for an unknown address');
        Accounts::startReset('zeynep@example.com');
        $code = $lastCode();
        try {
            Accounts::reset('zeynep@example.com', $code, 'yenisifre9', 'baska1234');
            throw new LogicException('different passwords accepted');
        } catch (\Sofrexa\Core\ValidationError $e) {
            same(['password2'], array_keys($e->errors));
        }
        Accounts::reset('zeynep@example.com', $code, 'yenisifre9', 'yenisifre9');
        same($a['id'], Accounts::current()['id']);
        check((bool) Accounts::login('zeynep@example.com', 'yenisifre9', false)['verified_at'], 'new password works');
    },

    'cart, minimum for delivery, acceptance with a time, tracking and e-mails' => function () use ($setup, $register, $mails): void {
        $s = $setup();
        [$a, $code] = $register();
        $a = Accounts::verify($a['id'], $code);
        $cart = [['item' => $s['soup'], 'qty' => 1]];
        same(42000, OnlineOrders::priced($cart)['subtotal']);
        try {
            OnlineOrders::place($a, $cart, ['type' => 'delivery', 'phone' => '0533 604 55 12']);
            throw new LogicException('below the minimum for delivery');
        } catch (\InvalidArgumentException $e) {
            check(str_contains($e->getMessage(), '80'), 'says how much more: ' . $e->getMessage());
        }
        $addr = Accounts::addAddress($a['customer_id'], 'Ev', 'Deniz Sk. No 7, Daire 3', 'Karaoğlanoğlu');
        $cart = [['item' => $s['pizza'], 'qty' => 1], ['item' => $s['soup'], 'qty' => 2]];
        $before = $mails();
        $id = OnlineOrders::place($a, $cart, ['type' => 'delivery', 'address_id' => $addr, 'phone' => '0533 604 55 12', 'pay' => 'cash', 'cash_given' => '2.000', 'note' => 'Zile basmayın']);
        $o = Orders::get($id);
        same(['online', 'pending', 149000], [$o['channel'], $o['status'], (int) $o['total']], '650 + 2 × 420');
        same('Karaoğlanoğlu, Deniz Sk. No 7, Daire 3', $o['delivery']['address']);
        same(200000, $o['delivery']['cash_given']);
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'online' AND ref_id = ? AND role = 'cashier'", [$id]), 'cashiers are told');
        same($before + 1, $mails(), '"received" e-mail');
        same('received', OnlineOrders::track($o)['stage']);

        // the till accepts it: 30 minutes; the kitchen, the courier, delivered
        Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$s['boss']]));
        Clock::freeze(1_790_000_000_000);
        OnlineOrders::approve($id, 30);
        $o = Orders::get($id);
        same('open', $o['status']);
        same(1_790_000_000_000 + 30 * 60_000, $o['delivery']['eta_at']);
        same(['sent', 'sent'], array_column($o['lines'], 'status'));
        $t = OnlineOrders::track($o);
        same('kitchen', $t['stage']);
        same(['done', 'done', 'current', 'pending', 'pending'], array_column($t['steps'], 0));
        same($before + 2, $mails(), '"accepted" e-mail with the time');
        check(Delivery::isDelivery($o), 'goes with a courier');
        try {
            Delivery::move($id, 'way');
            throw new LogicException('out without a courier');
        } catch (\Sofrexa\Core\ValidationError) {
        }
        $courier = Seed::user('Emre Kurye', 'courier', '5555');
        Delivery::assign($id, $courier);
        Delivery::move($id, 'way');
        OnlineOrders::notices();
        $t = OnlineOrders::track(Orders::get($id));
        same('way', $t['stage']);
        check(str_contains($t['hero'][2], 'Emre'), 'courier on the hero');
        same($before + 3, $mails(), '"on the way" e-mail');
        Delivery::move($id, 'done');
        same('done', OnlineOrders::track(Orders::get($id))['stage']);
        OnlineOrders::notices();
        same($before + 3, $mails(), 'each e-mail once');
    },

    'pickup, rejection, closed hours and the web copy' => function () use ($setup, $register): void {
        $s = $setup();
        [$a, $code] = $register();
        $a = Accounts::verify($a['id'], $code);
        $id = OnlineOrders::place($a, [['item' => $s['soup'], 'qty' => 1]], ['type' => 'pickup', 'phone' => '0533 604 55 12', 'pay' => 'card']);
        $o = Orders::get($id);
        same('pickup', $o['delivery']['type'], 'pickup has no minimum');
        check(!Delivery::isDelivery($o), 'no courier for pickup');
        OnlineOrders::reject($id, 'Malzeme bitti');
        $t = OnlineOrders::track(Orders::get($id));
        same('cancelled', $t['stage']);
        same(['done', 'cancelled'], array_column($t['steps'], 0));

        Settings::set('online.hours', '00:00 – 00:01');
        same('closed', OnlineOrders::availability());
        Settings::set('online.hours', '');
        App::setConfig('role', 'web');
        App::setConfig('sync.key', 'test-key');
        same('offline', OnlineOrders::availability(), 'no word from the till');
        \Sofrexa\Sync\Status::markOk();
        same('open', OnlineOrders::availability());
        $id = OnlineOrders::place($a, [['item' => $s['soup'], 'qty' => 1]], ['type' => 'pickup', 'phone' => '0533 604 55 12']);
        same(null, Orders::get($id)['intake_at'], 'left for the till');
        App::setConfig('role', 'pc');
        App::setConfig('sync.key', '');
        same(1, OnlineOrders::intake(), 'the till announces it');
        Settings::set('online.enabled', false);
        same('off', OnlineOrders::availability());
    },
];
