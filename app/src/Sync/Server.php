<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{App, Clock, Db, Request, Response};
use Sofrexa\Modules\Backup\Backup;

/**
 * Endpoints on the web copy, called only by the till PC (signed with the shared key).
 * The PC's pushes double as a heartbeat: when they stop, QR and online ordering close (Sync\Status).
 */
final class Server
{
    public function pull(Request $req): void
    {
        $body = (string) file_get_contents('php://input');
        Protocol::checkRequest($body);
        $in = json_decode($body, true) ?: [];
        $since = (int) ($in['since'] ?? 0);
        $outbox = Db::rows('SELECT seq, tbl, row_id FROM sync_outbox WHERE seq > ? ORDER BY seq LIMIT ' . Client::BATCH, [$since]);
        Response::json([
            'ok' => true,
            'epoch' => Client::state('epoch', ''),
            'rows' => Apply::collect($outbox),
            'seq' => $outbox ? (int) end($outbox)['seq'] : $since,
        ]);
    }

    public function push(Request $req): void
    {
        $body = (string) file_get_contents('php://input');
        Protocol::checkRequest($body);
        $in = json_decode($body, true) ?: [];
        $epoch = Client::state('epoch', '');
        if (($in['epoch'] ?? '') !== $epoch) {
            Response::json(['ok' => true, 'epoch' => $epoch, 'applied' => 0]);
        }
        $n = Apply::rows((array) ($in['rows'] ?? []));
        Db::exec('DELETE FROM sync_outbox WHERE seq <= ?', [(int) ($in['ack'] ?? 0)]);
        Status::markOk();
        // the till moved online orders along: tell the customers by e-mail
        if ($n > 0) {
            try {
                \Sofrexa\Modules\Online\OnlineOrders::notices();
            } catch (\Throwable $e) {
                App::log('mail', 'online notices: ' . $e->getMessage());
            }
        }
        Response::json(['ok' => true, 'epoch' => $epoch, 'applied' => $n]);
    }

    public function media(Request $req): void
    {
        $body = (string) file_get_contents('php://input');
        Protocol::checkRequest($body, true);
        $rel = str_replace('\\', '/', (string) ($_GET['path'] ?? ''));
        if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[\w\-]+(/[\w\-. ]+)+$#', $rel)) {
            Response::json(['ok' => false, 'error' => 'path'], 422);
        }
        $file = App::storage('uploads') . '/' . $rel;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $body);
        Response::json(['ok' => true]);
    }

    /** Chunked encrypted snapshot: when the last part arrives, the web copy becomes an exact copy of the PC. */
    public function snapshot(Request $req): void
    {
        $file = self::receive('snapshot');
        if ($file === null) {
            Response::json(['ok' => true, 'received' => (int) $_GET['part']]);
        }
        $zip = substr($file, 0, -4);
        try {
            Protocol::decryptFile($file, $zip);
            Backup::restore($zip, false, (string) ($_GET['epoch'] ?? ''));
        } finally {
            @unlink($file);
            @unlink($zip);
        }
        // The copied database carries the PC's queue and state; the web copy starts clean.
        Db::exec('DELETE FROM sync_outbox');
        Db::exec("DELETE FROM sync_state WHERE key NOT IN ('epoch')");
        Status::markOk();
        Response::json(['ok' => true, 'restored' => true]);
    }

    /** Chunked encrypted backup kept as-is (disaster recovery for the till PC). */
    public function backup(Request $req): void
    {
        $file = self::receive('backup');
        if ($file === null) {
            Response::json(['ok' => true, 'received' => (int) $_GET['part']]);
        }
        $dir = App::storage('backups') . '/from-pc';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = preg_replace('/[^\w.\-]/', '', (string) ($_GET['name'] ?? 'backup.zip')) . '.enc';
        rename($file, $dir . '/' . $name);
        foreach (array_slice(array_reverse(glob($dir . '/*.enc') ?: []), 14) as $old) {
            @unlink($old); // keep two weeks of nightly copies
        }
        Response::json(['ok' => true, 'stored' => $name]);
    }

    /** Stores one chunk; returns the assembled file path once every part is present and the checksum matches. */
    private static function receive(string $kind): ?string
    {
        $body = (string) file_get_contents('php://input');
        Protocol::checkRequest($body, true);
        $id = preg_replace('/[^\w\-]/', '', (string) ($_GET['id'] ?? ''));
        $part = (int) ($_GET['part'] ?? -1);
        $parts = (int) ($_GET['parts'] ?? 0);
        if ($id === '' || $part < 0 || $parts < 1 || $part >= $parts || $parts > 2000) {
            Response::json(['ok' => false, 'error' => 'chunk'], 422);
        }
        $dir = App::storage('backups') . '/incoming-' . $kind . '-' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . str_pad((string) $part, 5, '0', STR_PAD_LEFT), $body);
        $have = glob($dir . '/*') ?: [];
        if (count($have) < $parts) {
            return null;
        }
        sort($have);
        $out = $dir . '.enc';
        $w = fopen($out, 'wb');
        foreach ($have as $p) {
            fwrite($w, (string) file_get_contents($p));
            @unlink($p);
        }
        fclose($w);
        @rmdir($dir);
        if (!hash_equals((string) ($_GET['sha'] ?? ''), hash_file('sha256', $out))) {
            @unlink($out);
            Response::json(['ok' => false, 'error' => 'checksum'], 422);
        }
        return $out;
    }
}
