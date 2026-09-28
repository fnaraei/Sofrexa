<?php
declare(strict_types=1);

namespace Sofrexa\Setup;

use Sofrexa\Core\{App, Clock, Db, Settings};
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\Sync\Client;

/**
 * Once-a-day jobs on the till PC, run by the worker from backup.hour on (default 03:00, restaurant closed):
 * automatic backup (+ USB copy, + encrypted copy on the web copy), and housekeeping.
 * Loyalty (points expiry, automatic tiers) and the recurring expenses of the day.
 * A PC that was off at that hour catches up when it starts; each job counts as done only when it finished, and a
 * failed one is tried again an hour later (the ones that worked are not repeated).
 */
final class Nightly
{
    public const RETRY_MS = 3_600_000;

    /** @var array<string, callable> extra jobs registered by other modules */
    private static array $jobs = [];

    public static function register(string $name, callable $job): void
    {
        self::$jobs[$name] = $job;
    }

    public static function due(): bool
    {
        if (!App::isPc()) {
            return false;
        }
        $now = intdiv(Clock::ms(), 1000);
        if ((int) date('G', $now) < (int) Settings::get('backup.hour', 3) || Client::state('nightly_day') === date('Y-m-d', $now)) {
            return false;
        }
        return (int) Client::state('nightly_try', '0') <= Clock::ms() - self::RETRY_MS;
    }

    public static function run(callable $say): void
    {
        $today = date('Y-m-d', intdiv(Clock::ms(), 1000));
        Client::setState('nightly_try', (string) Clock::ms());
        $steps = [
            'backup' => static function () use ($say): void {
                if (!Settings::get('backup.nightly', true)) {
                    return;
                }
                $file = Backup::create('auto');
                $say('backup ' . basename($file) . ' · ' . Backup::size((int) filesize($file)));
                if (Settings::get('backup.to_web', true) && (string) App::config('sync.remote_url', '') !== '') {
                    try {
                        Client::sendBackup($file);
                        $say('backup sent to the web copy');
                    } catch (\Throwable $e) {
                        // the backup itself is made; the copy on the web goes with tomorrow's
                        App::log('nightly', 'backup to web failed: ' . $e->getMessage());
                        $say('backup to web failed: ' . $e->getMessage());
                    }
                }
            },
            'loyalty' => static fn() => $say('loyalty ' . \Sofrexa\Modules\Customers\Loyalty::nightly()),
            'recurring' => static fn() => $say('recurring expenses booked ' . \Sofrexa\Modules\Finance\Finance::runRecurring()),
        ];
        foreach (self::$jobs as $name => $job) {
            $steps['job:' . $name] = static function () use ($job, $say, $name): void {
                $job($say);
                $say("job $name done");
            };
        }
        $ok = true;
        foreach ($steps as $name => $step) {
            if (Client::state('nightly:' . $name) === $today) {
                continue; // done earlier today (this is a retry after another job failed)
            }
            try {
                $step();
                Client::setState('nightly:' . $name, $today);
            } catch (\Throwable $e) {
                $ok = false;
                App::log('nightly', "$name failed: " . $e->getMessage());
                $say("$name failed: " . $e->getMessage());
            }
        }
        if ($ok) {
            Client::setState('nightly_day', $today);
        }
        // housekeeping
        Db::exec('DELETE FROM rate_limits WHERE reset_at < ?', [Clock::ms()]);
        Db::exec('DELETE FROM notifications WHERE read_at IS NOT NULL AND at < ?', [Clock::ms() - 30 * 86_400_000]);
        foreach (glob(App::storage('prints') . '/*') ?: [] as $f) {
            if (filemtime($f) < time() - 14 * 86400) {
                @unlink($f);
            }
        }
        Db::exec('PRAGMA optimize');
    }
}
