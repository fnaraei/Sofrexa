<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{App, Clock, Db, Sync, Uuid};
use Sofrexa\Modules\Backup\Backup;

/**
 * Runs on the till PC (bin/sofrexa sync --loop, started with Windows). One round:
 *   1. pull   — take the web copy's changes (QR and online orders, remote edits) and apply them
 *   2. push   — send local changes; this is also the heartbeat that keeps QR/online ordering open
 *   3. media  — upload new or changed files (logo, menu photos)
 * When the web copy has another epoch (first start, or after a restore on the PC) the whole
 * database is sent as an encrypted snapshot instead.
 */
final class Client
{
    public const BATCH = 800;

    public static function round(): array
    {
        if (!App::isPc() || (string) App::config('sync.remote_url', '') === '') {
            return ['skipped' => 'no web copy configured'];
        }
        $epoch = self::epoch();
        $since = (int) self::state('pull_seq', '0');

        $pull = Protocol::post('/sync/pull', ['since' => $since, 'epoch' => $epoch]);
        if (($pull['epoch'] ?? '') !== $epoch) {
            return ['snapshot' => self::snapshot()];
        }
        $pulled = Apply::rows((array) ($pull['rows'] ?? []));
        if (isset($pull['seq'])) {
            self::setState('pull_seq', (string) (int) $pull['seq']);
        }

        $outbox = Db::rows('SELECT seq, tbl, row_id FROM sync_outbox ORDER BY seq LIMIT ' . self::BATCH);
        $rows = Apply::collect($outbox);
        $push = Protocol::post('/sync/push', ['epoch' => $epoch, 'rows' => $rows, 'ack' => (int) ($pull['seq'] ?? $since)]);
        if (($push['epoch'] ?? $epoch) !== $epoch) {
            return ['snapshot' => self::snapshot()];
        }
        if ($outbox) {
            Db::exec('DELETE FROM sync_outbox WHERE seq <= ?', [end($outbox)['seq']]);
        }
        $media = self::media();
        Status::markOk();
        return ['pulled' => $pulled, 'pushed' => count($rows), 'media' => $media, 'more' => count($outbox) === self::BATCH];
    }

    /** Sends the whole database + uploads, encrypted, in chunks; the web copy restores it. */
    public static function snapshot(): string
    {
        $epoch = self::epoch();
        $dir = App::storage('backups');
        $zip = $dir . '/snapshot-' . bin2hex(random_bytes(4)) . '.zip';
        Backup::export($zip, 'sync');
        $enc = $zip . '.enc';
        Protocol::encryptFile($zip, $enc);
        @unlink($zip);
        try {
            self::upload('/sync/snapshot', $enc, ['epoch' => $epoch]);
        } finally {
            @unlink($enc);
        }
        // The web copy now holds everything we have: start the incremental queues from zero.
        Db::exec('DELETE FROM sync_outbox');
        self::setState('pull_seq', '0');
        self::setState('media_at', (string) Clock::ms());
        Status::markOk();
        return $epoch;
    }

    /** Nightly: a copy of the latest backup is kept on the web copy (encrypted) for disaster recovery. */
    public static function sendBackup(string $zipPath): void
    {
        $enc = $zipPath . '.enc';
        Protocol::encryptFile($zipPath, $enc);
        try {
            self::upload('/sync/backup', $enc, ['name' => basename($zipPath)]);
        } finally {
            @unlink($enc);
        }
        Backup::addPlace(basename($zipPath), 'web');
    }

    /** Files under storage/uploads changed since the last round. */
    private static function media(): int
    {
        $since = (int) self::state('media_at', '0');
        $base = realpath(App::storage('uploads'));
        if (!$base) {
            return 0;
        }
        $now = Clock::ms();
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getMTime() * 1000 <= $since - 2000 || str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            Protocol::post('/sync/media', (string) file_get_contents($f->getPathname()), 'application/octet-stream', ['path' => $rel]);
            if (++$n >= 30) {
                return $n; // the rest next round; media_at stays where it was
            }
        }
        self::setState('media_at', (string) $now);
        return $n;
    }

    private static function upload(string $path, string $file, array $query): void
    {
        $size = (int) filesize($file);
        $parts = max(1, (int) ceil($size / Protocol::CHUNK));
        $id = Uuid::v7();
        $sha = hash_file('sha256', $file);
        $fp = fopen($file, 'rb');
        for ($i = 0; $i < $parts; $i++) {
            $chunk = (string) fread($fp, Protocol::CHUNK);
            Protocol::post($path, $chunk, 'application/octet-stream', $query + ['id' => $id, 'part' => $i, 'parts' => $parts, 'sha' => $sha]);
        }
        fclose($fp);
    }

    public static function epoch(): string
    {
        $e = self::state('epoch');
        if ($e === null) {
            $e = bin2hex(random_bytes(8));
            self::setState('epoch', $e);
        }
        return $e;
    }

    public static function state(string $key, ?string $default = null): ?string
    {
        $v = Db::value('SELECT value FROM sync_state WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function setState(string $key, string $value): void
    {
        Db::exec('INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)', [$key, $value]);
    }

    /** Records every replicated row in the outbox (used once when a web copy is first configured). */
    public static function queueAll(): int
    {
        $n = 0;
        foreach ([...Sync::MUTABLE, ...Sync::APPEND] as $t) {
            $n += Db::exec('INSERT INTO sync_outbox (tbl, row_id, at) SELECT ?, id, ? FROM ' . Db::ident($t), [$t, Clock::ms()]);
        }
        return $n;
    }
}
