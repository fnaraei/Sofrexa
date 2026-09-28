<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{App, Clock, Db, I18n};

/**
 * Connection state shown by the SyncStatus badge.
 *  online  — the PC and the web copy exchanged data recently (or no web copy is configured)
 *  syncing — changes are waiting to be sent
 *  offline — no contact for longer than sync.heartbeat_timeout; the restaurant keeps working locally
 */
final class Status
{
    public static function get(): array
    {
        $state = 'online';
        $pending = (int) Db::value('SELECT COUNT(*) FROM sync_outbox');
        $last = (int) (Db::value("SELECT value FROM sync_state WHERE key = 'last_ok'") ?? 0);
        $timeout = (int) App::config('sync.heartbeat_timeout', 60) * 1000;
        $configured = App::isPc() ? App::config('sync.remote_url') !== '' : App::config('sync.key') !== '';
        if ($configured) {
            if (Clock::ms() - $last > $timeout) {
                $state = 'offline';
            } elseif (App::isPc() && $pending > 0) {
                $state = 'syncing';
            }
        }
        return [
            'state' => $state,
            'label' => I18n::t('sync.' . $state),
            'pending' => $pending,
            'last_ok' => $last ?: null,
            'configured' => $configured,
        ];
    }

    public static function markOk(): void
    {
        Db::exec("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('last_ok', ?)", [(string) Clock::ms()]);
    }
}
