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
        // guest QR orders made on the web copy: to the kitchen or to a waiter for approval; the result goes back in this push
        \Sofrexa\Modules\QrOrder\QrOrders::intake();
        \Sofrexa\Modules\Online\OnlineOrders::intake();

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
        // Where the queue stood when the copy was taken: everything queued up to here is in the snapshot. A change made while
        // it is exported or uploaded is queued after this mark and still goes out with the next push.
        $mark = (int) Db::value('SELECT COALESCE(MAX(seq), 0) FROM sync_outbox');
        $files = self::files();
        Backup::export($zip, 'sync');
        $enc = $zip . '.enc';
        Protocol::encryptFile($zip, $enc);
        @unlink($zip);
        try {
            self::upload('/sync/snapshot', $enc, ['epoch' => $epoch]);
        } finally {
            @unlink($enc);
        }
        // The web copy now holds everything we had at the mark: the incremental queues go on from there.
        Db::exec('DELETE FROM sync_outbox WHERE seq <= ?', [$mark]);
        self::setState('pull_seq', '0');
        Db::tx(static function () use ($files): void {
            Db::exec('DELETE FROM sync_media');
            foreach ($files as $rel => [$mtime, $size]) {
                Db::exec('INSERT INTO sync_media (path, mtime, size) VALUES (?, ?, ?)', [$rel, $mtime, $size]);
            }
        });
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

    /** Public files under storage/uploads: relative path => [modified time, size]. Private ones (receipts) never leave the PC. */
    private static function files(): array
    {
        $base = realpath(App::storage('uploads'));
        if (!$base) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            if ($rel === 'private' || str_starts_with($rel, 'private/')) {
                continue;
            }
            $out[$rel] = [(int) $f->getMTime(), (int) $f->getSize()];
        }
        ksort($out);
        return $out;
    }

    /**
     * New or changed files, compared with the list of what the web copy already has (sync_media): at most 30 a round, each
     * written to that list as soon as the web copy took it, so the next round goes on with the 31st.
     */
    private static function media(): int
    {
        $base = realpath(App::storage('uploads'));
        if (!$base) {
            return 0;
        }
        $sent = [];
        foreach (Db::rows('SELECT path, mtime, size FROM sync_media') as $r) {
            $sent[$r['path']] = [(int) $r['mtime'], (int) $r['size']];
        }
        $n = 0;
        foreach (self::files() as $rel => $stat) {
            if (($sent[$rel] ?? null) === $stat) {
                continue;
            }
            Protocol::post('/sync/media', (string) file_get_contents($base . '/' . $rel), 'application/octet-stream', ['path' => $rel]);
            Db::exec('INSERT OR REPLACE INTO sync_media (path, mtime, size) VALUES (?, ?, ?)', [$rel, $stat[0], $stat[1]]);
            if (++$n >= 30) {
                break; // the rest next round
            }
        }
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
