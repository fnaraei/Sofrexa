<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, I18n};

/**
 * Emergency mode (PLAN stage 13): when the till PC is broken, the restaurant works on the web copy for a while.
 * A manager switches it on from the web copy, only while the PC is not in touch. While it is on, the web copy takes
 * guest QR and online orders in itself, keeps them open, and accepts PIN sign-ins from the address the manager
 * used (the restaurant's own line). There are no printers; the kitchen reads the screen.
 * It ends by hand, or by itself as soon as the PC reaches the web copy again: the PC then pulls everything that
 * happened meanwhile through the normal sync (web order numbers start at 5001, so nothing clashes).
 */
final class Emergency
{
    private const KEY = 'emergency';

    /** {at, by, name, ip} or null. Kept in sync_state: it belongs to this copy and is never replicated. */
    public static function state(): ?array
    {
        if (!App::isWeb()) {
            return null;
        }
        $v = Db::value('SELECT value FROM sync_state WHERE key = ?', [self::KEY]);
        $s = $v ? json_decode((string) $v, true) : null;
        return is_array($s) ? $s : null;
    }

    public static function on(): bool
    {
        return self::state() !== null;
    }

    /** Only on the web copy, and only while the PC is silent (otherwise two tills would take orders). */
    public static function start(string $ip): void
    {
        if (!App::isWeb()) {
            throw new \InvalidArgumentException(I18n::t('emg.err_web'));
        }
        if (Status::get()['state'] !== 'offline') {
            throw new \InvalidArgumentException(I18n::t('emg.err_online'));
        }
        $u = Auth::user();
        Db::exec('INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)', [self::KEY, json_encode([
            'at' => Clock::ms(), 'by' => $u['id'] ?? null, 'name' => $u['name'] ?? '', 'ip' => $ip,
        ], JSON_UNESCAPED_UNICODE)]);
        Audit::log('sync.emergency_on', 'IP ' . $ip);
    }

    /** $why: manual (a manager) or pc (the PC is back). */
    public static function end(string $why = 'manual'): void
    {
        $s = self::state();
        if (!$s) {
            return;
        }
        Db::exec('DELETE FROM sync_state WHERE key = ?', [self::KEY]);
        Audit::log('sync.emergency_off', $why === 'pc' ? 'PC geri geldi' : 'elle', null, null, [], $why === 'pc' ? ['id' => null, 'name' => 'Sync'] : null);
    }

    /** PIN sign-in from the restaurant's line during emergency mode. */
    public static function allows(string $ip): bool
    {
        $s = self::state();
        return $s !== null && ($s['ip'] ?? '') === $ip;
    }
}
