<?php
declare(strict_types=1);

namespace Sofrexa\Print;

use Sofrexa\Core\{App, Clock, Db, Uuid};

/**
 * Print queue (print_jobs). The worker (bin/sofrexa worker, started with Windows) prints queued tickets
 * within a second and keeps retrying when a printer is off or out of paper, so no kitchen ticket is lost.
 * Only the till PC prints: the web copy never creates jobs.
 */
final class Spooler
{
    public const MAX_ATTEMPTS = 20;

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

    /** Worker pass: retries queued jobs oldest first. Returns the number printed. */
    public static function work(): int
    {
        $n = 0;
        foreach (Db::rows("SELECT * FROM print_jobs WHERE status IN ('queued', 'retry') AND attempts < ? ORDER BY at LIMIT 20", [self::MAX_ATTEMPTS]) as $job) {
            if (self::run($job)) {
                $n++;
            }
        }
        Db::exec("DELETE FROM print_jobs WHERE status = 'done' AND done_at < ?", [Clock::ms() - 7 * 86_400_000]);
        return $n;
    }

    public static function pending(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued', 'retry')");
    }

    private static function run(?array $job): bool
    {
        if (!$job) {
            return false;
        }
        try {
            Printer::send($job['printer'], (string) base64_decode($job['payload']));
            Db::update('print_jobs', ['status' => 'done', 'done_at' => Clock::ms(), 'attempts' => $job['attempts'] + 1, 'error' => null], 'id = ?', [$job['id']]);
            return true;
        } catch (\Throwable $e) {
            $attempts = $job['attempts'] + 1;
            Db::update('print_jobs', ['status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry', 'attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 300)], 'id = ?', [$job['id']]);
            App::log('print', 'failed: ' . $e->getMessage(), ['printer' => $job['printer'], 'kind' => $job['kind']]);
            return false;
        }
    }
}
