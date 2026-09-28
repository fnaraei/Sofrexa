<?php
declare(strict_types=1);

namespace Sofrexa\Print;

use Sofrexa\Core\{App, Clock, Db, I18n, Settings};

/**
 * The restaurant's thermal printers (SE1 "Yazıcılar", SE4). Two physical printers — till/bar and
 * kitchen — and logical outputs (bar tickets, courier slips) mapped onto them in settings.
 *
 * Drivers:
 *   tcp      network printer, target "192.168.1.60:9100"
 *   share    Windows printer share, target "\\MUTFAK-PC\Thermal80" (sent with copy /b, raw)
 *   windows  local USB printer shared under a name, target "POS80" (= \\localhost\POS80)
 *   file     writes storage/prints/*.bin and a readable .txt (development, or a printer that is not installed yet)
 */
final class Printer
{
    public const PHYSICAL = ['cashier', 'kitchen'];

    public static function config(string $which): array
    {
        $which = self::resolve($which);
        $saved = Db::value("SELECT value FROM settings WHERE id = ? AND deleted = 0", ['printer.' . $which]);
        $cfg = $saved ? json_arr($saved) : ((array) App::config('printers.' . $which, []) + (array) Settings::get('printer.' . $which));
        return $cfg + ['driver' => 'file', 'target' => '', 'width' => 80];
    }

    /** bar → the printer set for bar tickets, courier → the one for courier slips. */
    public static function resolve(string $which): string
    {
        return match ($which) {
            'bar' => (string) Settings::get('printer.bar_on', 'cashier'),
            'courier' => (string) Settings::get('printer.courier_on', 'cashier'),
            default => in_array($which, self::PHYSICAL, true) ? $which : 'cashier',
        };
    }

    /** Rows for the settings page: cashier, kitchen, courier. */
    public static function all(): array
    {
        $out = [];
        foreach (['cashier', 'kitchen', 'courier'] as $which) {
            $same = $which === 'courier';
            $cfg = self::config($which);
            $st = self::status(self::resolve($which));
            $out[$which] = [
                'key' => $which,
                'same' => $same,
                'cfg' => $cfg,
                'status' => $st['state'],
                'error' => $st['error'],
                'line' => $same ? I18n::t('set.prn.same') : self::describe($cfg) . ' · ' . I18n::t('set.prn.' . $which . '_use'),
            ];
        }
        return $out;
    }

    public static function describe(array $cfg): string
    {
        $mm = ($cfg['width'] ?? 80) . ' mm';
        return match ($cfg['driver']) {
            'tcp' => 'IP · ' . $cfg['target'] . ' · ' . $mm,
            'share' => I18n::t('set.prn.d_share') . ' · ' . $cfg['target'] . ' · ' . $mm,
            'windows' => 'USB · ' . ($cfg['target'] ?: '—') . ' · ' . $mm,
            default => I18n::t('set.prn.d_file') . ' · ' . $mm,
        };
    }

    /** ready | error | unknown, from the last attempt on this PC. */
    public static function status(string $which): array
    {
        $v = json_arr(Db::value('SELECT value FROM sync_state WHERE key = ?', ['printer:' . $which]));
        return ['state' => $v ? ($v['ok'] ? 'ready' : 'error') : 'unknown', 'error' => $v['error'] ?? null, 'at' => $v['at'] ?? null];
    }

    private static function remember(string $which, bool $ok, ?string $error = null): void
    {
        Db::exec('INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)', ['printer:' . $which, json_encode(['ok' => $ok, 'error' => $error, 'at' => Clock::ms()], JSON_UNESCAPED_UNICODE)]);
    }

    /** Sends raw ESC/POS bytes now. Throws on failure (and remembers the error for the status badge). */
    public static function send(string $which, string $bytes): void
    {
        $which = self::resolve($which);
        $cfg = self::config($which);
        try {
            match ($cfg['driver']) {
                'tcp' => self::tcp((string) $cfg['target'], $bytes),
                'share', 'windows' => self::share($cfg['driver'] === 'windows' ? '\\\\localhost\\' . ltrim((string) $cfg['target'], '\\') : (string) $cfg['target'], $bytes),
                default => self::file($which, $bytes),
            };
            self::remember($which, true);
        } catch (\Throwable $e) {
            self::remember($which, false, $e->getMessage());
            throw $e;
        }
    }

    public static function test(string $which): void
    {
        $cfg = self::config($which);
        $p = new EscPos((int) $cfg['width']);
        $p->align('c')->bold()->size(2, 2)->text((string) (Settings::get('profile.short_name') ?: Settings::get('profile.name')))->size()->bold(false)
            ->text('TEST FİŞİ · ' . I18n::t('set.prn.' . $which, [], 'tr'))
            ->text(date('d.m.Y H:i'))->align('l')->hr()
            ->text('Türkçe: ğüşıöç ĞÜŞİÖÇ')
            ->pair('Adana Kebap x1', '870,00 TL')
            ->pair('Ayran x2', '120,00 TL')
            ->hr()->bold()->pair('TOPLAM', '990,00 TL')->bold(false)
            ->align('c')->text(self::describe($cfg))->text('powered by SOFREXA')->feed(2)->cut();
        self::send($which, $p->bytes());
    }

    private static function tcp(string $target, string $bytes): void
    {
        [$host, $port] = array_pad(explode(':', $target, 2), 2, '9100');
        if ($host === '') {
            throw new \RuntimeException('no address');
        }
        $fp = @fsockopen($host, (int) $port, $errno, $err, 3);
        if (!$fp) {
            throw new \RuntimeException("$host:$port $err");
        }
        stream_set_timeout($fp, 5);
        fwrite($fp, $bytes);
        fclose($fp);
    }

    private static function share(string $unc, string $bytes): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('Windows printer shares need the till PC');
        }
        if (!preg_match('#^\\\\\\\\[\w.\-]+\\\\[\w.\- ]+$#', $unc)) {
            throw new \RuntimeException('bad printer path');
        }
        $tmp = App::storage('prints') . '/job-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($tmp, $bytes);
        exec('copy /b ' . escapeshellarg($tmp) . ' ' . escapeshellarg($unc) . ' 2>&1', $out, $code);
        @unlink($tmp);
        if ($code !== 0) {
            throw new \RuntimeException(trim(implode(' ', $out)) ?: 'copy failed');
        }
    }

    private static function file(string $which, string $bytes): void
    {
        $dir = App::storage('prints');
        $base = $dir . '/' . date('Ymd-His') . '-' . $which . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        file_put_contents($base . '.bin', $bytes);
        file_put_contents($base . '.txt', self::preview($bytes));
    }

    /** Readable text of an ESC/POS job: control sequences dropped, code page 857 decoded. */
    public static function preview(string $bytes): string
    {
        $out = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $c = $bytes[$i];
            if ($c === "\x1B") {
                $cmd = $bytes[$i + 1] ?? '';
                $i += match ($cmd) { '@' => 1, 'B' => 3, 'p' => 4, default => 2 };
            } elseif ($c === "\x1D") {
                $cmd = $bytes[$i + 1] ?? '';
                if ($cmd === 'v') { // raster image: GS v 0 m xL xH yL yH data
                    $w = ord($bytes[$i + 4]) + 256 * ord($bytes[$i + 5]);
                    $h = ord($bytes[$i + 6]) + 256 * ord($bytes[$i + 7]);
                    $i += 7 + $w * $h;
                    $out .= "[LOGO]\n";
                } elseif ($cmd === '(') { // GS ( k pL pH ...
                    $len = ord($bytes[$i + 3]) + 256 * ord($bytes[$i + 4]);
                    $i += 4 + $len;
                    $out .= str_ends_with($out, "[QR]\n") ? '' : "[QR]\n";
                } else {
                    $i += $cmd === 'V' ? 3 : 2;
                }
            } elseif ($c === "\n" || ord($c) >= 0x20) {
                $out .= $c;
            }
        }
        return @iconv('CP857', 'UTF-8//IGNORE', $out) ?: $out;
    }
}
