<?php
/** Core behaviour: money, languages, permissions, sign-in lock, append-only tables, sync apply, printing. */
declare(strict_types=1);

use Sofrexa\Core\{Auth, Clock, Db, I18n, Money, Perms, Sync};
use Sofrexa\Print\{EscPos, Printer};
use Sofrexa\Setup\Seed;
use Sofrexa\Sync\Apply;

return [
    'money format and parse' => function (): void {
        same('₺1.234', Money::fmt(123400, false, 'tr'));
        same('₺1.234,50', Money::fmt(123450, false, 'tr'));
        same('−₺5.640', Money::fmt(-564000, false, 'tr'));
        same(123450, Money::parse('1.234,50'));
        same(50000, Money::parse('₺500'));
        same(4280, Money::parse('۴۲٫۸۰'));
    },

    'i18n: four languages side by side, Persian digits, fallback' => function (): void {
        same('Hoş geldiniz', I18n::t('login.welcome', [], 'tr'));
        same('خوش آمدید', I18n::t('login.welcome', [], 'fa'));
        same('۳ بار اشتباه. ۳۰ ثانیهٔ دیگر دوباره امتحان کنید.', I18n::t('login.locked', ['wait' => 30], 'fa'));
        same('unknown.key', I18n::t('unknown.key'));
        same('Menu', I18n::pick(['en' => 'Menu'], 'fa'));
        same('Pazartesi, 28 Eylül', I18n::date(strtotime('2026-09-28 12:00') * 1000, 'long', 'tr'));
    },

    'permissions: implied ones and per-user deny' => function (): void {
        $p = Perms::expand(['cash.pay', 'staff.manage']);
        check(in_array('delivery.manage', $p, true) && in_array('users.manage', $p, true), 'implied permissions');
        Seed::base(static fn() => null);
        $id = Seed::user('Can Test', 'cashier', '4821');
        Db::save('users', ['id' => $id, 'perms_deny' => ['cash.nosale']]);
        Auth::actAs(Db::row('SELECT u.*, r.code AS role_code, r.perms AS role_perms FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]));
        check(Auth::can('cash.pay') && Auth::can('bill.print'), 'role + implied');
        check(!Auth::can('cash.nosale'), 'personal deny wins');
        check(!Auth::can('settings.manage'), 'no settings for cashier');
    },

    'PIN: three wrong tries lock for 30 seconds' => function (): void {
        Seed::base(static fn() => null);
        $id = Seed::user('Ayşe Test', 'waiter', '4821');
        Clock::freeze(1_800_000_000_000);
        for ($i = 0; $i < 2; $i++) {
            same('login.wrong_pin', Auth::loginPin($id, '0000', '127.0.0.1')['error']);
        }
        same('login.locked', Auth::loginPin($id, '0000', '127.0.0.1')['error']);
        same('login.locked', Auth::loginPin($id, '4821', '127.0.0.1')['error'], 'even the right PIN waits');
        Clock::freeze(1_800_000_031_000);
        $_SESSION = [];
        check(@Auth::loginPin($id, '4821', '127.0.0.1')['ok'], 'unlocked after 30 s');
    },

    'signing in by PIN keeps the session, and changing a password ends the old ones' => function (): void {
        Seed::base(static fn() => null);
        $waiter = Seed::user('Ayşe Test', 'waiter', '4821');
        $_SESSION = [];
        check(@Auth::loginPin($waiter, '4821', '127.0.0.1')['ok'], 'the PIN is right');
        // most staff have no password at all; the session check used to compare '' with null and throw them out
        same($waiter, Auth::user()['id'] ?? null, 'a PIN-only user stays signed in');

        $boss = Seed::user('Patron Test', 'manager', '9182');
        Db::save('users', ['id' => $boss, 'password_hash' => password_hash('ilk-parola', PASSWORD_DEFAULT)]);
        $_SESSION = [];
        check(@Auth::loginPin($boss, '9182', '127.0.0.1')['ok'], 'the manager signs in by PIN as well');
        same($boss, Auth::user()['id'] ?? null, 'a user with a password stays signed in too');
        Db::save('users', ['id' => $boss, 'password_hash' => password_hash('yeni-parola', PASSWORD_DEFAULT)]);
        Auth::actAs(null);
        (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, false);
        same(null, Auth::user(), 'the session opened with the old password is over');
    },

    'audit log and cash moves cannot be changed or deleted' => function (): void {
        Db::append('audit_log', ['at' => 1, 'action' => 'test', 'summary' => 'x']);
        foreach (['UPDATE audit_log SET summary = ?' => ['y'], 'DELETE FROM audit_log' => []] as $sql => $params) {
            try {
                Db::exec($sql, $params);
                throw new LogicException('allowed: ' . $sql);
            } catch (PDOException $e) {
                check(str_contains($e->getMessage(), 'append-only'), 'trigger message');
            }
        }
    },

    'sync apply: last write wins, append-only inserted once' => function (): void {
        Db::exec("INSERT INTO settings (id, value, updated_at) VALUES ('x', '1', 100)");
        Apply::rows([['t' => 'settings', 'r' => ['id' => 'x', 'value' => '2', 'updated_at' => 50, 'deleted' => 0]]]);
        same('1', Db::value("SELECT value FROM settings WHERE id = 'x'"), 'older row ignored');
        Apply::rows([['t' => 'settings', 'r' => ['id' => 'x', 'value' => '3', 'updated_at' => 200, 'deleted' => 0]]]);
        same('3', Db::value("SELECT value FROM settings WHERE id = 'x'"), 'newer row applied');
        $row = ['id' => 'a1', 'at' => 5, 'action' => 'test', 'summary' => 'once'];
        Apply::rows([['t' => 'audit_log', 'r' => $row], ['t' => 'audit_log', 'r' => $row + ['summary' => 'twice']]]);
        same(1, (int) Db::value('SELECT COUNT(*) FROM audit_log'), 'append-only once');
        same(0, (int) Db::value('SELECT COUNT(*) FROM sync_outbox'), 'applied rows are not echoed');
        check(!Sync::enabled(), 'PC without a web copy records nothing');
    },

    'printing: Turkish code page, columns, file driver' => function (): void {
        $p = (new EscPos(80))->pair('Adana Kebap x1', '870,00 TL')->text('ğüşıöç');
        same("\x1B\x40\x1B\x74\x0D", substr($p->bytes(), 0, 5), 'init + PC857');
        check(str_contains($p->bytes(), str_pad('Adana Kebap x1', 38) . ' 870,00 TL'), '48-column pair');
        same("ğüşıöç\n", mb_substr(Printer::preview((new EscPos())->text('ğüşıöç')->bytes()), 0, 7), 'round trip');
        Printer::test('kitchen');
        same('ready', Printer::status('kitchen')['state']);
    },
];
