<?php
declare(strict_types=1);

namespace Sofrexa\Setup;

use Sofrexa\Core\{App, Auth, Clock, Db, Perms};

/**
 * First-run data: default roles, loyalty tiers, and (development only) demo staff and tables.
 * Every step is idempotent — running it again never duplicates or overwrites edited rows.
 */
final class Seed
{
    public const ROLE_NAMES = ['waiter' => 'Garson', 'cashier' => 'Kasa', 'chef' => 'Şef', 'stock' => 'Depocu', 'courier' => 'Kurye', 'manager' => 'Yönetici'];

    /** Bronze / Silver / Gold (design handoff §5): yearly-spend threshold in kuruş, discount %, earn %. */
    public const TIERS = [
        // badge tones as on Figma CU5: Bronz neutral, Gümüş accent, Altın solid
        ['Bronz', 0, 0, 5, 'Neutral'],
        ['Gümüş', 1_000_000, 3, 7, 'Accent'],
        ['Altın', 2_500_000, 5, 10, 'Solid'],
    ];

    /** Demo staff for development and training (names from the Figma screens). Never used in production. */
    public const DEMO_STAFF = [
        ['Yönetici Demo', 'manager', '1234', 'demo-manager@sofrexa.test', 'demo-Manager-2026'],
        ['Ayşe Yıldız', 'waiter', '1111', null, null],
        ['Mehmet Kaya', 'waiter', '2222', null, null],
        ['Zeynep Demir', 'waiter', '3333', null, null],
        ['Can Öztürk', 'cashier', '4444', null, null],
        ['Hakan Şahin', 'chef', '5555', null, null],
        ['Emre Kılıç', 'courier', '6666', null, null],
    ];

    public static function base(callable $say): void
    {
        Db::tx(static function () use ($say): void {
            $sort = 0;
            foreach (Perms::ROLES as $code => $perms) {
                $sort += 10;
                if (!Db::value('SELECT 1 FROM roles WHERE code = ?', [$code])) {
                    Db::save('roles', ['code' => $code, 'name' => self::ROLE_NAMES[$code], 'perms' => $perms, 'is_system' => 1, 'sort' => $sort]);
                    $say("role $code");
                }
            }
            if (!Db::value('SELECT COUNT(*) FROM tiers WHERE deleted = 0')) {
                foreach (self::TIERS as $i => [$name, $threshold, $disc, $earn, $tone]) {
                    Db::save('tiers', ['name' => $name, 'threshold' => $threshold, 'discount_pct' => $disc, 'earn_pct' => $earn, 'tone' => $tone, 'sort' => ($i + 1) * 10]);
                }
                $say('tiers Bronz / Gümüş / Altın');
            }
        });
        $say('Base data ready.');
    }

    /** Creates a staff user. PIN: 4 digits; e-mail + password only for remote sign-in. */
    public static function user(string $name, string $role, string $pin, ?string $email = null, ?string $password = null): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new \InvalidArgumentException('PIN must be 4 digits.');
        }
        $roleId = Db::value('SELECT id FROM roles WHERE code = ? AND deleted = 0', [$role]);
        if (!$roleId) {
            throw new \InvalidArgumentException("Unknown role: $role (run seed first)");
        }
        return Db::save('users', [
            'name' => $name,
            'role_id' => $roleId,
            'pin_hash' => Auth::hashPin($pin),
            'email' => $email ?: null,
            'password_hash' => $password ? password_hash($password, PASSWORD_DEFAULT) : null,
            'remote_login' => $password ? 1 : 0,
            'created_at' => Clock::ms(),
            'sort' => (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM users'),
        ]);
    }

    /** Demo staff, floor plan and a few customers. Refused unless debug is on (development machines). */
    public static function demo(callable $say): void
    {
        if (!App::config('debug')) {
            throw new \RuntimeException('Demo data is only allowed when debug = true in app/config.php.');
        }
        foreach (self::DEMO_STAFF as [$name, $role, $pin, $email, $pw]) {
            if (!Db::value('SELECT 1 FROM users WHERE name = ? AND deleted = 0', [$name])) {
                self::user($name, $role, $pin, $email, $pw);
                $say("user $name ($role)");
            }
        }
        if (!Db::value('SELECT COUNT(*) FROM areas')) {
            $plan = [
                'Bahçe' => [range(1, 14), ['tr' => 'Bahçe', 'en' => 'Garden', 'fa' => 'باغ', 'ru' => 'Сад'], 0],
                'Salon' => [range(1, 12), ['tr' => 'Salon', 'en' => 'Dining room', 'fa' => 'سالن', 'ru' => 'Зал'], 0],
                'Sigara salonu' => [range(1, 8), ['tr' => 'Sigara salonu', 'en' => 'Smoking room', 'fa' => 'سالن سیگار', 'ru' => 'Зал для курящих'], 1],
            ];
            $sort = 0;
            foreach ($plan as $area => [$numbers, $names, $smoking]) {
                $areaId = Db::save('areas', ['name' => $area, 'names' => $names, 'smoking' => $smoking, 'sort' => $sort += 10]);
                foreach ($numbers as $i => $n) {
                    Db::save('tables', ['area_id' => $areaId, 'number' => (string) $n, 'seats' => $n % 3 === 0 ? 6 : 4, 'code' => self::tableCode(), 'sort' => $i * 10]);
                }
            }
            $say('3 areas and 34 tables');
        }
        $say('Demo data ready (PINs are listed in app/src/Setup/Seed.php → DEMO_STAFF).');
    }

    /** Random 6-character code for a table QR link, same format as the website's cards (at least one letter). */
    public static function tableCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (!preg_match('/[a-z]/', $code) || Db::value('SELECT 1 FROM tables WHERE code = ?', [$code]));
        return $code;
    }
}
