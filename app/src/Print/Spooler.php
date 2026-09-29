<?php
declare(strict_types=1);

namespace Sofrexa\Print;

use Sofrexa\Core\{App, Clock, Db, Uuid};

/**
 * Print queue (print_jobs). The worker (bin/sofrexa worker, started with Windows) prints queued tickets
 * within a second and keeps retrying when a printer is off or out of paper — with growing pauses (up to 30 s),
 * never giving up — so no kitchen ticket is lost; the till is told when a printer keeps failing.
 * Only a drawer kick is dropped after a minute (the drawer must not spring open long after the sale).
 * Only the till PC prints: the web copy never creates jobs.
 */
final class Spooler
{
    /** After this many failures in a row the till gets a "printer is not printing" notification. */
    public const ALERT_AFTER = 5;
    public const MAX_PAUSE_MS = 30_000;
    public const DRAWER_TTL_MS = 60_000;

    /**
     * Queue a ticket. The worker prints it within a second; web requests never wait for a printer
     * (an unplugged printer would otherwise hold the single-threaded local server for seconds).
     * $now = true prints inside this request (CLI and tests).
     */
    public static function print(string $printer, string $kind, string $bytes, ?string $refId = null, bool $now = false): bool
    {
        if (App::isWeb()) {
            return false;
        }
        $id = Uuid::v7();
        Db::insert('print_jobs', ['id' => $id, 'printer' => $printer, 'kind' => $kind, 'ref_id' => $refId, 'payload' => base64_encode($bytes), 'at' => Clock::ms()]);
        return $now ? self::run(Db::row('SELECT * FROM print_jobs WHERE id = ?', [$id])) : true;
    }

    /** Worker pass: prints the jobs that are due, oldest first. Returns the number printed. */
    public static function work(): int
    {
        $now = Clock::ms();
        Db::exec("UPDATE print_jobs SET status = 'expired' WHERE kind = 'drawer' AND status IN ('queued', 'retry') AND at < ?", [$now - self::DRAWER_TTL_MS]);
        $n = 0;
        // one queue per physical printer (the bar and courier tickets go to the printer they are set to): each prints its
        // tickets in the order they were made — while its oldest waiting ticket is paused for a retry, the newer ones wait
        // behind it, so a cancellation never comes out before its order (audit 8 O16, audit 9 R07) — and each queue is
        // read on its own, so however many tickets pile up for a printer that is off, the others print (R04)
        $queues = [];
        foreach (array_column(Db::rows("SELECT DISTINCT printer FROM print_jobs WHERE status IN ('queued', 'retry')"), 'printer') as $name) {
            $queues[Printer::resolve((string) $name)][] = (string) $name;
        }
        foreach ($queues as $names) {
            foreach (Db::rows("SELECT * FROM print_jobs WHERE status IN ('queued', 'retry') AND printer IN (" . Db::in($names) . ') ORDER BY at, rowid LIMIT 20', $names) as $job) {
                if (($job['next_at'] !== null && (int) $job['next_at'] > $now) || !self::run($job)) {
                    break; // waiting for its retry, or just failed: nothing behind it goes first
                }
                $n++;
            }
        }
        // CAST: PDO sends numbers as text, and SQLite only turns them back into numbers next to a plain column
        Db::exec("DELETE FROM print_jobs WHERE status IN ('done', 'expired') AND COALESCE(done_at, at) < CAST(? AS INTEGER)", [$now - 7 * 86_400_000]);
        return $n;
    }

    public static function pending(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued', 'retry')");
    }

    /** "Şimdi dene": the waiting tickets of a printer are tried at the next worker pass. */
    public static function retryNow(string $printer): int
    {
        return Db::exec("UPDATE print_jobs SET next_at = NULL WHERE printer = ? AND status IN ('queued', 'retry')", [$printer]);
    }

    /** Printers that keep failing: printer => [waiting tickets, last error]. */
    public static function stuck(): array
    {
        $out = [];
        foreach (Db::rows("SELECT printer, COUNT(*) AS n, MAX(attempts) AS a, MAX(error) AS e FROM print_jobs WHERE status = 'retry' GROUP BY printer") as $r) {
            if ((int) $r['a'] >= self::ALERT_AFTER) {
                $out[$r['printer']] = ['n' => (int) $r['n'], 'error' => (string) $r['e']];
            }
        }
        return $out;
    }

    private static function run(?array $job): bool
    {
        if (!$job) {
            return false;
        }
        try {
            Printer::send($job['printer'], (string) base64_decode($job['payload']));
            Db::update('print_jobs', ['status' => 'done', 'done_at' => Clock::ms(), 'attempts' => $job['attempts'] + 1, 'error' => null, 'next_at' => null], 'id = ?', [$job['id']]);
            self::recovered($job['printer']);
            return true;
        } catch (\Throwable $e) {
            $attempts = $job['attempts'] + 1;
            // 1 s, 2 s, 4 s … then every 30 s until the printer is back
            $pause = min(self::MAX_PAUSE_MS, 1000 * (2 ** min(15, $attempts - 1)));
            Db::update('print_jobs', ['status' => 'retry', 'attempts' => $attempts, 'next_at' => Clock::ms() + $pause, 'error' => mb_substr($e->getMessage(), 0, 300)], 'id = ?', [$job['id']]);
            App::log('print', 'failed: ' . $e->getMessage(), ['printer' => $job['printer'], 'kind' => $job['kind'], 'attempt' => $attempts]);
            if ($attempts === self::ALERT_AFTER) {
                self::alert($job['printer']);
            }
            return false;
        }
    }

    /** "Mutfak yazıcısı yazdırmıyor · 3 fiş bekliyor" for the till (one open notice per printer). */
    private static function alert(string $printer): void
    {
        $n = (int) Db::value("SELECT COUNT(*) FROM print_jobs WHERE printer = ? AND status IN ('queued', 'retry')", [$printer]);
        $open = Db::value("SELECT id FROM notifications WHERE kind = 'printer' AND ref_type = 'printer' AND ref_id = ? AND done_at IS NULL AND deleted = 0", [$printer]);
        $body = ['where' => $printer, 'n' => $n];
        if ($open) {
            Db::save('notifications', ['id' => $open, 'body' => $body, 'at' => Clock::ms(), 'read_at' => null]);
        } else {
            Db::save('notifications', ['role' => 'cashier', 'kind' => 'printer', 'title' => $printer, 'body' => $body, 'ref_type' => 'printer', 'ref_id' => $printer, 'at' => Clock::ms()]);
        }
    }

    /** The printer works again: its notice closes. */
    private static function recovered(string $printer): void
    {
        foreach (Db::rows("SELECT id FROM notifications WHERE kind = 'printer' AND ref_type = 'printer' AND ref_id = ? AND done_at IS NULL AND deleted = 0", [$printer]) as $n) {
            Db::save('notifications', ['id' => $n['id'], 'read_at' => Clock::ms(), 'done_at' => Clock::ms()]);
        }
    }
}
