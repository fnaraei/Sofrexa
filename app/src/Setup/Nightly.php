<?php
declare(strict_types=1);

namespace Sofrexa\Setup;

use Sofrexa\Core\{App, Clock, Db, Settings};
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\Sync\Client;

/**
 * Once-a-day jobs on the till PC, run by the worker at backup.hour (default 03:00, restaurant closed):
 * automatic backup (+ USB copy, + encrypted copy on the web copy), and housekeeping.
 * Loyalty: points expiry and the automatic tiers. Later stages add: recurring expenses.
 */
final class Nightly
{
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
        $today = date('Y-m-d');
        return (int) date('G') === (int) Settings::get('backup.hour', 3)
            && Client::state('nightly_day') !== $today;
    }

    public static function run(callable $say): void
    {
        Client::setState('nightly_day', date('Y-m-d'));
        if (Settings::get('backup.nightly', true)) {
            $file = Backup::create('auto');
            $say('backup ' . basename($file) . ' · ' . Backup::size((int) filesize($file)));
            if (Settings::get('backup.to_web', true) && (string) App::config('sync.remote_url', '') !== '') {
                try {
                    Client::sendBackup($file);
                    $say('backup sent to the web copy');
                } catch (\Throwable $e) {
                    App::log('nightly', 'backup to web failed: ' . $e->getMessage());
                    $say('backup to web failed: ' . $e->getMessage());
                }
            }
        }
        $say('loyalty ' . \Sofrexa\Modules\Customers\Loyalty::nightly());
        foreach (self::$jobs as $name => $job) {
            try {
                $job($say);
                $say("job $name done");
            } catch (\Throwable $e) {
                App::log('nightly', "$name failed: " . $e->getMessage());
            }
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
