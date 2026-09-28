<?php
/** QR ordering: approval of the first order of a table session, direct orders after it, the guest's status, rejection, the web copy. */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db};
use Sofrexa\Modules\Menu\{Floor, Menu};
use Sofrexa\Modules\Orders\{Board, Orders, Shifts};
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\Setup\Seed;

$as = static function (?string $uid): void {
    Auth::actAs($uid ? Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]) : null);
};
$setup = static function () use ($as): array {
    Seed::base(static fn() => null);
    $boss = Seed::user('Patron', 'manager', '1111');
    $waiter = Seed::user('Ayşe Yıldız', 'waiter', '2222');
    $as($boss);
    $kitchen = Db::save('categories', ['names' => ['tr' => 'Izgaralar'], 'station' => 'kitchen']);
    $bar = Db::save('categories', ['names' => ['tr' => 'İçecekler'], 'station' => 'bar']);
    $grill = Menu::saveItem(['names' => ['tr' => 'Adana Kebap', 'en' => 'Adana Kebab'], 'category_id' => $kitchen, 'price' => '870', 'available' => 1, 'show_qr' => 1]);
    $tea = Menu::saveItem(['names' => ['tr' => 'Çay'], 'category_id' => $bar, 'price' => '100', 'available' => 1, 'show_qr' => 1]);
    $hidden = Menu::saveItem(['names' => ['tr' => 'Personel yemeği'], 'category_id' => $kitchen, 'price' => '50', 'available' => 1]);
    Menu::toggle($hidden, 'show_qr', false);
    $table = Floor::table(Floor::addTable(Floor::saveArea(['names' => ['tr' => 'Bahçe']]), '7'));
    Shifts::open(['TRY' => 0]);
    $as(null); // the guest has no account
    return compact('boss', 'waiter', 'grill', 'tea', 'hidden', 'table');
};
$line = static fn(string $item, int $qty, string $note = ''): array => ['item' => $item, 'qty' => $qty, 'mods' => [], 'note' => $note];

return [
    'first order waits for a waiter, the next go straight to the kitchen, paying closes the session' => function () use ($setup, $as, $line): void {
        $s = $setup();
        $t = $s['table'];
        same($t['id'], QrOrders::table($t['code'])['id'], 'table by its card code');
        same($t['id'], QrOrders::table('7')['id'], 'old card with the table number');

        [$o1, $sid] = QrOrders::submit($t, [$line($s['grill'], 2, 'acısız'), $line($s['tea'], 1)], 'Çocuk için ekstra tabak');
        $o = Orders::get($o1);
        same('pending', $o['status']);
        same(184000, (int) $o['total'], '2 × 870 + 100');
        check((bool) $o['intake_at'], 'taken in by the till');
        same(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'qr' AND ref_id = ? AND role = 'waiter'", [$o1]), 'waiters are told');
        same('pending', QrOrders::status($t, [$o1])['state']);
        $tile = null;
        foreach (Board::areas()[0]['tables'] as $x) {
            $tile = $x['id'] === $t['id'] ? $x : $tile;
        }
        same('qr', $tile['state'], 'the table tile asks for approval');
        same(null, Orders::openForTable($t['id']), 'a waiting QR order is not the table bill');

        $as($s['waiter']);
        same($o1, QrOrders::accept($o1, $s['waiter']));
        $o = Orders::get($o1);
        same('open', $o['status']);
        same($s['waiter'], $o['waiter_id'], 'the approving waiter owns the table');
        same(['sent', 'sent'], array_column($o['lines'], 'status'));
        check((bool) Db::value('SELECT approved_at FROM qr_sessions WHERE id = ?', [$sid]), 'session approved');
        same(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE kind = 'qr' AND ref_id = ? AND done_at IS NULL", [$o1]), 'alert closed');

        // the waiter adds a dish that is not sent yet; the next QR order joins the bill without sending it
        $mine = Orders::addItem($o1, $s['tea'], 1);
        $as(null);
        [$o2, $sid2] = QrOrders::submit($t, [$line($s['tea'], 2)]);
        same($sid, $sid2, 'same table session');
        $x = Db::row('SELECT * FROM orders WHERE id = ?', [$o2]);
        same(['void', $o1], [$x['status'], $x['merged_into']], 'direct: joined the table bill');
        same(null, $x['approved_by']);
        same('new', Orders::line($mine)['status'], "the waiter's unsent line stays unsent");
        same(204000 + 10000, (int) Orders::get($o1)['total']);
        $st = QrOrders::status($t, [$o1, $o2]);
        same('kitchen', $st['state']);
        same(['done', 'current', 'upcoming'], array_column($st['steps'], 0), 'no approval step for a direct order');
        same(214000, $st['total'], 'the whole table bill');
        check($st['can_bill'], 'the bill can be asked for');

        // "Hesap iste" and "Garson çağır" from the table
        check(QrOrders::requestBill($t), 'bill asked');
        check((bool) Orders::get($o1)['bill_at'], 'bill marked on the till');
        check(!QrOrders::requestBill($t), 'once a minute');
        check(QrOrders::callWaiter($t), 'waiter called');
        same($s['waiter'], Db::value("SELECT user_id FROM notifications WHERE kind = 'call' ORDER BY rowid DESC LIMIT 1"), 'the call goes to the table waiter');

        // paying the bill closes the session: the next order needs approval again
        $as($s['boss']);
        Orders::send($o1);
        Orders::pay($o1, [['method' => 'card', 'amount' => (int) Orders::get($o1)['total']]], null, false);
        check((bool) Db::value('SELECT closed_at FROM qr_sessions WHERE id = ?', [$sid]), 'session closed');
        $as(null);
        [$o3, $sid3] = QrOrders::submit($t, [$line($s['grill'], 1)]);
        check($sid3 !== $sid, 'a new session');
        same('pending', Orders::get($o3)['status'], 'approval again');
        check(QrOrders::needsApproval(QrOrders::session($t['id'])), 'new guests');
    },

    'rejection, choices and hidden dishes, the web copy leaves orders to the till' => function () use ($setup, $as, $line): void {
        $s = $setup();
        $t = $s['table'];
        [$o1] = QrOrders::submit($t, [$line($s['grill'], 1)]);
        $as($s['waiter']);
        QrOrders::reject($o1);
        $as(null);
        same('rejected', QrOrders::status($t, [$o1])['state']);
        try {
            QrOrders::accept($o1, $s['waiter']);
            throw new LogicException('accepted a rejected order');
        } catch (\InvalidArgumentException) {
        }
        foreach ([[$line($s['hidden'], 1)], [], [$line($s['grill'], 0)]] as $bad) {
            try {
                QrOrders::submit($t, $bad);
                throw new LogicException('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
            }
        }
        same([], array_filter(array_merge(...array_column(QrOrders::menu(), 'items')), static fn(array $i): bool => $i['id'] === $s['hidden']), 'not on the QR menu');

        // a required choice
        $as($s['boss']);
        $g = Db::save('modifier_groups', ['names' => ['tr' => 'Acılık'], 'min_sel' => 1, 'max_sel' => 1, 'kind' => 'single']);
        Db::save('modifiers', ['group_id' => $g, 'names' => ['tr' => 'Acısız', 'en' => 'Mild'], 'sort' => 1]);
        Db::save('modifiers', ['group_id' => $g, 'names' => ['tr' => 'Acılı'], 'sort' => 2]);
        Menu::setGroups($s['grill'], [$g]);
        $as(null);
        try {
            QrOrders::submit($t, [$line($s['grill'], 1)]);
            throw new LogicException('missing choice accepted');
        } catch (\InvalidArgumentException) {
        }
        $opt = Db::value('SELECT id FROM modifiers WHERE group_id = ? ORDER BY sort LIMIT 1', [$g]);
        [$o2] = QrOrders::submit($t, [['item' => $s['grill'], 'qty' => 1, 'mods' => [$opt], 'note' => '']]);
        same('Acısız', QrOrders::lineNote(Orders::get($o2)['lines'][0]));

        // on the web copy (a PC behind it) orders wait for the till; with no word from the till ordering pauses
        App::setConfig('role', 'web');
        App::setConfig('sync.key', 'test-key');
        same('offline', QrOrders::availability());
        try {
            QrOrders::submit($t, [$line($s['tea'], 1)]);
            throw new LogicException('ordered while the till is away');
        } catch (\InvalidArgumentException) {
        }
        \Sofrexa\Sync\Status::markOk();
        same('open', QrOrders::availability());
        [$o3] = QrOrders::submit($t, [$line($s['tea'], 1)]);
        same(null, Orders::get($o3)['intake_at'], 'left for the till');
        same(0, QrOrders::intake(), 'only the till takes orders in');
        App::setConfig('role', 'pc');
        App::setConfig('sync.key', '');
        check(QrOrders::intake() >= 1, 'the till takes it in');
        check((bool) Orders::get($o3)['intake_at'], 'intake time set');
    },

    'an edit beats the version it replaces even when the clock is behind' => function (): void {
        $id = Db::save('areas', ['name' => 'Bahçe', 'names' => ['tr' => 'Bahçe']]);
        $future = Clock::ms() + 60_000;
        Db::exec('UPDATE areas SET updated_at = ? WHERE id = ?', [$future, $id]);
        Db::save('areas', ['id' => $id, 'name' => 'Teras']);
        same($future + 1, (int) Db::value('SELECT updated_at FROM areas WHERE id = ?', [$id]));
    },
];
