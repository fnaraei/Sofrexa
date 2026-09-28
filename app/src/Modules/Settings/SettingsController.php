<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Settings;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, I18n, Money, Request, Response, Settings, ValidationError, View};
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\Print\Printer;
use Sofrexa\Sync\Status;

/**
 * Settings — SE1 (desktop, sub-navigation + section cards), SE2 (mobile list), SE3–SE9.
 * Each section is one page with one form; fields are posted as s[<setting key>].
 */
final class SettingsController
{
    /** key => icon, in the Figma sub-navigation order. */
    public const SECTIONS = [
        'profile' => 'store', 'currency' => 'currency', 'online' => 'globe', 'qr' => 'qr', 'printers' => 'printer',
        'vat' => 'percent', 'languages' => 'globe', 'backup' => 'cloud-check', 'security' => 'lock',
    ];

    /** Plain settings per section and how each is read from the form. */
    private const FIELDS = [
        'profile' => [
            'profile.name' => 'str', 'profile.legal_name' => 'str', 'profile.tax_no' => 'str', 'profile.phone' => 'str',
            'profile.email' => 'email', 'profile.website' => 'str', 'profile.address' => 'str', 'profile.hours' => 'str',
            'profile.short_name' => 'str', 'profile.tagline' => 'str',
            'receipt.header' => 'str', 'receipt.footer' => 'str', 'receipt.logo' => 'bool', 'receipt.powered_by' => 'bool',
        ],
        'online' => [
            'online.enabled' => 'bool', 'online.delivery' => 'bool', 'online.pickup' => 'bool', 'online.min_order' => 'money',
            'online.delivery_fee' => 'money', 'online.eta_minutes' => 'str', 'online.hours' => 'str', 'online.area' => 'str',
        ],
        'qr' => ['qr.enabled' => 'bool', 'qr.require_first_approval' => 'bool', 'qr.call_waiter' => 'bool', 'qr.request_bill' => 'bool'],
        'languages' => ['lang.staff' => 'langs', 'lang.customer' => 'langs'],
        'backup' => ['backup.nightly' => 'bool', 'backup.hour' => 'hour', 'backup.usb' => 'bool', 'backup.usb_path' => 'str', 'backup.to_web' => 'bool'],
        'security' => ['security.pin_networks' => 'nets', 'security.idle_lock_minutes' => 'int'],
        'vat' => ['vat.food' => 'pct', 'vat.drinks' => 'pct', 'vat.alcohol' => 'pct'],
    ];

    /** SE2 on phones; on the till PC the first section opens directly. */
    public function index(Request $req): void
    {
        $data = ['title' => I18n::t('set.title'), 'nav' => 'settings', 'tab' => 'more', 'summaries' => self::summaries()];
        $u = Auth::user();
        $data['appSub'] = $u['name'] . ' · ' . I18n::t('role.' . $u['role_code']);
        View::page('settings/index', $data);
    }

    public function section(Request $req): void
    {
        $key = $req->param('section');
        if (!isset(self::SECTIONS[$key])) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        View::page('settings/section', [
            'title' => I18n::t('set.title'),
            'sub' => I18n::t('set.sub'),
            'nav' => 'settings',
            'back' => '/settings',
            'section' => $key,
            'mTitle' => I18n::t($key === 'profile' ? 'set.list.profile' : ($key === 'backup' ? 'set.bk.m_title' : 'set.nav.' . $key)),
            'mSub' => self::mobileSub($key),
            'data' => self::data($key),
            'scripts' => ['js/settings.js'],
        ]);
    }

    public function save(Request $req): void
    {
        $key = $req->param('section');
        if (!isset(self::SECTIONS[$key])) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $in = $req->arr('s');
        $values = [];
        $errors = [];
        foreach (self::FIELDS[$key] ?? [] as $k => $type) {
            if ($type !== 'bool' && $type !== 'langs' && !array_key_exists($k, $in)) {
                continue; // not on this form: keep the stored value
            }
            $v = $in[$k] ?? null;
            $values[$k] = match ($type) {
                'bool' => !empty($v),
                'int' => max(0, min(600, (int) read_num($v, $k))),
                'hour' => max(0, min(23, (int) read_num($v, $k))),
                'pct' => max(0, min(100, read_num($v, $k))),
                'money' => max(0, Money::parse((string) $v)),
                'langs' => array_values(array_intersect(array_keys(I18n::LANGS), (array) $v)),
                'nets' => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $v) ?: []))),
                'email' => strtolower(trim((string) $v)),
                default => mb_substr(trim((string) $v), 0, 300),
            };
            if ($type === 'email' && $values[$k] !== '' && !filter_var($values[$k], FILTER_VALIDATE_EMAIL)) {
                $errors["s[$k]"] = I18n::t('users.err_email');
            }
            if ($type === 'langs' && !$values[$k]) {
                $errors["s[$k]"] = I18n::t('set.lang.need_one');
            }
            if ($type === 'nets') {
                foreach ($values[$k] as $net) {
                    if (!self::validNet($net)) {
                        $errors["s[$k]"] = I18n::t('set.sec.bad_net', ['net' => $net]);
                    }
                }
            }
        }
        if ($key === 'profile' && ($values['profile.name'] ?? '') === '') {
            $errors['s[profile.name]'] = I18n::t('ui.required');
        }
        if ($key === 'security' && $values['security.pin_networks'] && !self::ipIn($req->ip(), $values['security.pin_networks']) && !self::ipIn($req->ip(), (array) App::config('pin_networks', []))) {
            $errors['s[security.pin_networks]'] = I18n::t('set.sec.lockout');
        }
        if ($errors) {
            throw new ValidationError($errors);
        }

        $changes = [];
        Db::tx(static function () use ($key, $values, $in, $req, &$changes): void {
            foreach ($values as $k => $v) {
                $old = Settings::get($k);
                if ($old !== $v && json_encode($old) !== json_encode($v)) {
                    Settings::set($k, $v);
                    $changes[] = self::describe($k, $old, $v);
                }
            }
            match ($key) {
                'currency' => self::saveCurrency($in, $changes),
                'printers' => self::savePrinters($req->arr('p'), $in, $changes),
                'vat' => self::saveVat($req->arr('cat'), $changes),
                default => null,
            };
        });
        if ($changes) {
            Audit::log('settings.save', implode(' · ', array_slice($changes, 0, 6)) . (count($changes) > 6 ? ' …' : ''), 'settings', $key, ['changes' => $changes]);
        }
        Response::json(['ok' => true, 'message' => I18n::t('set.saved')]);
    }

    // -------------------------------------------------------------- logo

    public function logo(Request $req): void
    {
        $f = $_FILES['logo'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        $mime = $f && is_uploaded_file($f['tmp_name']) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2_097_152 || !isset($types[$ext]) || !in_array($mime, [...array_values($types), 'text/xml', 'image/svg'], true)) {
            Response::fail($req, I18n::t('set.logo.bad'));
        }
        $dir = App::storage('uploads') . '/brand';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if ($ext === 'svg') {
            $svg = (string) file_get_contents($f['tmp_name']);
            if (preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $svg)) {
                Response::fail($req, I18n::t('set.logo.bad'));
            }
        }
        $name = 'logo-' . date('YmdHis') . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        move_uploaded_file($f['tmp_name'], $dir . '/' . $name);
        $old = (string) Settings::get('profile.logo', '');
        Settings::set('profile.logo', 'brand/' . $name);
        Audit::log('settings.save', 'Logo · ' . $f['name'], 'settings', 'profile');
        if ($old !== '' && $old !== 'brand/' . $name && is_file(App::storage('uploads') . '/' . $old)) {
            @unlink(App::storage('uploads') . '/' . $old);
        }
        Response::json(['ok' => true, 'message' => I18n::t('set.logo.saved'), 'redirect' => '/settings/profile']);
    }

    public function logoRemove(Request $req): void
    {
        Settings::set('profile.logo', '');
        Audit::log('settings.save', 'Logo · ' . I18n::t('ui.remove', [], 'tr'), 'settings', 'profile');
        Response::json(['ok' => true, 'message' => I18n::t('set.logo.removed'), 'redirect' => '/settings/profile']);
    }

    // -------------------------------------------------------------- printers

    public function printerTest(Request $req): void
    {
        $which = $req->param('printer');
        try {
            Printer::test($which);
            Response::json(['ok' => true, 'message' => I18n::t('set.prn.test_ok')]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => I18n::t('set.prn.test_fail', ['error' => $e->getMessage()])], 422);
        }
    }

    // -------------------------------------------------------------- data for the views

    public static function data(string $key): array
    {
        return match ($key) {
            'profile' => ['logo' => self::logoInfo()],
            'currency' => ['rates' => self::rates()],
            'printers' => ['printers' => Printer::all()],
            'vat' => ['categories' => Db::rows('SELECT id, names, section, slug, vat_rate FROM categories WHERE deleted = 0 ORDER BY sort')],
            'backup' => ['backups' => Backup::list(), 'sync' => Status::get()],
            'security' => ['turnstile' => \Sofrexa\Core\Turnstile::enabled()],
            default => [],
        };
    }

    /** Phone app-bar subtitle per section (SE3, SE4, SE6, SE8). */
    private static function mobileSub(string $key): string
    {
        return match ($key) {
            'profile' => I18n::t('set.profile.m_sub'),
            'online' => I18n::t('set.online.m_sub'),
            'printers' => I18n::t('set.prn.m_sub'),
            'backup' => ($last = Backup::list()[0]['at'] ?? null) ? I18n::t('set.bk.m_sub', ['when' => mb_strtolower(when_label($last))]) : I18n::t('set.bk.none'),
            default => '',
        };
    }

    /** Latest rate per accepted currency. */
    public static function rates(): array
    {
        $out = [];
        foreach (['GBP', 'USD', 'EUR'] as $c) {
            $r = Db::row('SELECT f.rate, f.at, u.name FROM fx_rates f LEFT JOIN users u ON u.id = f.user_id WHERE f.currency = ? ORDER BY f.at DESC LIMIT 1', [$c]);
            $out[$c] = ['rate' => $r ? (float) $r['rate'] : null, 'at' => $r['at'] ?? null, 'by' => $r['name'] ?? null, 'accepted' => in_array($c, (array) Settings::get('currency.accepted', []), true)];
        }
        return $out;
    }

    public static function logoInfo(): ?array
    {
        $logo = (string) Settings::get('profile.logo', '');
        $file = $logo !== '' ? App::storage('uploads') . '/' . $logo : '';
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $dim = @getimagesize($file);
        return ['url' => '/media/' . $logo, 'name' => basename($logo), 'size' => Backup::size((int) filesize($file)), 'w' => $dim[0] ?? null, 'h' => $dim[1] ?? null, 'ext' => strtoupper(pathinfo($file, PATHINFO_EXTENSION))];
    }

    /** One-line summaries for the SE2 list. */
    public static function summaries(): array
    {
        $rates = [];
        foreach (self::rates() as $c => $r) {
            if ($r['accepted'] && $r['rate']) {
                $rates[] = Money::symbol($c) . ' ' . I18n::num($r['rate'], 2);
            }
        }
        $fee = (int) Settings::get('online.delivery_fee', 0);
        $ready = count(array_filter(Printer::all(), static fn(array $p): bool => $p['status'] === 'ready' && !$p['same']));
        $food = Settings::get('vat.food', 10);
        $alc = Settings::get('vat.alcohol', 20);
        $last = Backup::list()[0]['at'] ?? null;
        return [
            'profile' => I18n::t('set.list.profile_sub'),
            'currency' => $rates ? implode(' · ', $rates) : I18n::t('set.cur.never'),
            'online' => Settings::get('online.enabled') ? I18n::t('set.list.online_on', ['min' => Money::fmt((int) Settings::get('online.min_order')), 'fee' => $fee ? Money::fmt($fee) : I18n::t('set.list.free_delivery')]) : I18n::t('set.list.online_off'),
            'qr' => Settings::get('qr.enabled') ? I18n::t(Settings::get('qr.require_first_approval') ? 'set.list.qr_on' : 'set.list.qr_on_direct') : I18n::t('set.list.online_off'),
            'printers' => I18n::t('set.list.printers', ['n' => I18n::num($ready)]),
            'vat' => I18n::t('set.list.vat', ['food' => I18n::num((float) $food), 'alcohol' => I18n::num((float) $alc)]),
            'languages' => implode(' · ', array_map('strtoupper', (array) Settings::get('lang.staff'))),
            'backup' => $last ? I18n::t('set.list.backup', ['time' => when_label($last)]) : I18n::t('set.list.no_backup'),
        ];
    }

    // -------------------------------------------------------------- section savers

    private static function saveCurrency(array $in, array &$changes): void
    {
        $accepted = array_values(array_intersect(['GBP', 'USD', 'EUR'], (array) ($in['currency.accepted'] ?? [])));
        if ($accepted !== array_values((array) Settings::get('currency.accepted'))) {
            Settings::set('currency.accepted', $accepted);
            $changes[] = 'Dövizler: ' . (implode(', ', $accepted) ?: '—');
        }
        foreach (self::rates() as $c => $r) {
            $raw = trim((string) ($in['rate.' . $c] ?? ''));
            if ($raw === '') {
                continue;
            }
            // "42,80" (Turkish) or "42.80"; with a comma present the dot is a thousands separator.
            $s = preg_replace('/[^\d.,]/', '', strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => ',', '٬' => '.'])) ?? '';
            $rate = round(str_contains($s, ',') ? (float) str_replace(',', '.', str_replace('.', '', $s)) : (float) $s, 4);
            if ($rate > 0 && abs($rate - (float) $r['rate']) > 0.00001) {
                Db::append('fx_rates', ['currency' => $c, 'rate' => $rate, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
                $changes[] = $c . ' ' . ($r['rate'] ? I18n::num((float) $r['rate'], 2, 'tr') . ' → ' : '') . I18n::num($rate, 2, 'tr');
            }
        }
    }

    private static function savePrinters(array $p, array $in, array &$changes): void
    {
        foreach (['cashier', 'kitchen'] as $which) {
            if (!isset($p[$which])) {
                continue;
            }
            $old = (array) Settings::get('printer.' . $which);
            $new = [
                'name' => $old['name'] ?? '',
                'driver' => in_array($p[$which]['driver'] ?? '', ['windows', 'share', 'tcp', 'file'], true) ? $p[$which]['driver'] : 'file',
                'target' => mb_substr(trim((string) ($p[$which]['target'] ?? '')), 0, 200),
                'width' => (int) ($p[$which]['width'] ?? 80) === 58 ? 58 : 80,
            ] + $old;
            if ($new !== $old) {
                Settings::set('printer.' . $which, $new);
                $changes[] = I18n::t('set.prn.' . $which, [], 'tr') . ': ' . $new['driver'] . ' ' . $new['target'];
            }
        }
        foreach (['printer.bar_on' => ['cashier', 'kitchen'], 'printer.courier_on' => ['cashier', 'kitchen']] as $k => $allowed) {
            if (isset($in[$k]) && in_array($in[$k], $allowed, true) && $in[$k] !== Settings::get($k)) {
                Settings::set($k, $in[$k]);
                $changes[] = $k . ' → ' . $in[$k];
            }
        }
    }

    private static function saveVat(array $cats, array &$changes): void
    {
        foreach ($cats as $id => $rate) {
            $rate = max(0, min(100, read_num($rate, 'vat')));
            $old = Db::row('SELECT names, vat_rate FROM categories WHERE id = ? AND deleted = 0', [(string) $id]);
            if ($old && abs((float) $old['vat_rate'] - $rate) > 0.001) {
                Db::save('categories', ['id' => (string) $id, 'vat_rate' => $rate]);
                $changes[] = tn($old['names'], 'tr') . ' %' . I18n::num((float) $old['vat_rate'], 0, 'tr') . ' → %' . I18n::num($rate, 0, 'tr');
            }
        }
    }

    /** Human-readable change for the activity log, in Turkish (the log language). */
    private static function describe(string $key, mixed $old, mixed $new): string
    {
        $fmt = static function (mixed $v) use ($key): string {
            if (is_bool($v)) {
                return $v ? 'açık' : 'kapalı';
            }
            if (is_array($v)) {
                return implode(', ', $v) ?: '—';
            }
            if (in_array($key, ['online.min_order', 'online.delivery_fee'], true)) {
                return Money::fmt((int) $v, false, 'tr');
            }
            return (string) $v === '' ? '—' : mb_strimwidth((string) $v, 0, 40, '…');
        };
        return $key . ' ' . $fmt($old) . ' → ' . $fmt($new);
    }

    private static function validNet(string $net): bool
    {
        [$ip, $bits] = array_pad(explode('/', $net, 2), 2, null);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ($bits === null || (ctype_digit($bits) && (int) $bits <= 32));
    }

    private static function ipIn(string $ip, array $nets): bool
    {
        foreach ($nets as $n) {
            [$net, $bits] = array_pad(explode('/', (string) $n, 2), 2, '32');
            $mask = -1 << (32 - (int) $bits);
            if (ip2long($ip) !== false && ip2long($net) !== false && (ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
