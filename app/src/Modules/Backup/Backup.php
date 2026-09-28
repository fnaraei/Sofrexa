<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Backup;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, I18n, Settings};

/**
 * Full backups (SE7–SE9). A backup is one .zip: db/sofrexa.sqlite (a consistent copy made with
 * VACUUM INTO while the app keeps running), the uploads folder (logo, menu photos) and manifest.json.
 * A small .json next to each zip records kind, author, size and where copies went (PC, USB, web).
 *
 * Restore copies the saved database page by page into the live one with SQLite's backup API, so it
 * works while other processes (print and sync workers) have the database open. A safety backup of
 * the current state is always taken first.
 */
final class Backup
{
    public const MAGIC = 'sofrexa-backup';

    public static function dir(): string
    {
        $d = App::storage('backups');
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    /** @param string $kind auto | manual | safety */
    public static function create(string $kind = 'manual'): string
    {
        $by = Auth::user();
        $at = Clock::ms();
        $name = 'sofrexa-' . date('Ymd-His', intdiv($at, 1000)) . '-' . $kind;
        $zipPath = self::dir() . '/' . $name . '.zip';
        $tmpDb = self::dir() . '/' . $name . '.sqlite.tmp';

        @unlink($tmpDb);
        Db::exec('VACUUM INTO ' . Db::pdo()->quote($tmpDb));
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ' . $zipPath);
        }
        $zip->addFile($tmpDb, 'db/sofrexa.sqlite');
        $uploads = realpath(App::storage('uploads'));
        $files = 0;
        if ($uploads) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1)));
                    $files++;
                }
            }
        }
        $manifest = [
            'magic' => self::MAGIC,
            'version' => SOFREXA_VERSION,
            'created_at' => $at,
            'kind' => $kind,
            'by' => $by['name'] ?? null,
            'device' => (string) App::config('device_name'),
            'restaurant' => Settings::get('profile.name'),
            'schema' => array_column(Db::rows('SELECT name FROM schema_migrations ORDER BY name'), 'name'),
            'uploads' => $files,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
        @unlink($tmpDb);

        $places = ['pc'];
        $usb = trim((string) Settings::get('backup.usb_path', ''));
        if ($usb !== '' && Settings::get('backup.usb', true) && is_dir($usb) && @copy($zipPath, rtrim($usb, '/\\') . '/' . basename($zipPath))) {
            $places[] = 'usb';
        }
        self::writeMeta($zipPath, $manifest + ['size' => filesize($zipPath), 'places' => $places]);
        if ($kind !== 'safety') {
            Audit::log('backup.create', ($kind === 'auto' ? 'Otomatik' : 'Elle') . ' · ' . self::size((int) filesize($zipPath)) . ' · ' . strtoupper(implode(' · ', $places)), 'backup', basename($zipPath));
        }
        self::prune();
        return $zipPath;
    }

    /** Backups, newest first: file, at, kind, by, size, places. */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/sofrexa-*.zip') ?: [] as $zip) {
            $meta = json_arr(@file_get_contents(substr($zip, 0, -4) . '.json') ?: null);
            $out[] = [
                'file' => basename($zip),
                'at' => (int) ($meta['created_at'] ?? filemtime($zip) * 1000),
                'kind' => $meta['kind'] ?? 'manual',
                'by' => $meta['by'] ?? null,
                'size' => (int) ($meta['size'] ?? filesize($zip)),
                'places' => $meta['places'] ?? ['pc'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        return $out;
    }

    public static function path(string $file): string
    {
        if (!preg_match('/^sofrexa-[\w-]+\.zip$/', $file) || !is_file(self::dir() . '/' . $file)) {
            throw new \InvalidArgumentException(I18n::t('err.not_found'));
        }
        return self::dir() . '/' . $file;
    }

    /** Records created after a backup (what a restore would remove), for the confirmation sheet. */
    public static function laterRecords(int $since): int
    {
        $n = 0;
        foreach (['orders' => 'opened_at', 'payments' => 'at', 'stock_moves' => 'at', 'cash_moves' => 'at', 'finance_entries' => 'at', 'audit_log' => 'at'] as $t => $col) {
            $n += (int) Db::value("SELECT COUNT(*) FROM $t WHERE $col > ?", [$since]);
        }
        return $n;
    }

    /** Restores a backup zip (path). Returns the file name of the safety backup of the previous state. */
    public static function restore(string $zipPath): string
    {
        if (!is_file($zipPath)) {
            throw new \InvalidArgumentException(I18n::t('err.not_found'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException(I18n::t('set.bk.bad_file'));
        }
        $manifest = json_arr($zip->getFromName('manifest.json') ?: null);
        if (($manifest['magic'] ?? '') !== self::MAGIC || $zip->locateName('db/sofrexa.sqlite') === false) {
            $zip->close();
            throw new \InvalidArgumentException(I18n::t('set.bk.bad_file'));
        }
        $actor = Auth::user();
        $safety = self::create('safety');

        $tmp = self::dir() . '/restore-' . bin2hex(random_bytes(4)) . '.sqlite';
        file_put_contents($tmp, $zip->getFromName('db/sofrexa.sqlite'));
        $src = new \SQLite3($tmp, SQLITE3_OPEN_READONLY);
        $src->busyTimeout(10_000);
        Db::disconnect();
        $dest = new \SQLite3((string) App::config('db'));
        $dest->busyTimeout(10_000);
        if (!$src->backup($dest)) {
            throw new \RuntimeException('Restore failed: ' . $dest->lastErrorMsg());
        }
        $src->close();
        $dest->close();
        @unlink($tmp);

        // Uploads: replace the folder content with the saved one.
        $uploads = App::storage('uploads');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }
            $target = $uploads . '/' . substr($name, 8);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $zip->getFromIndex($i));
        }
        $zip->close();

        // Newer migrations than the backup are re-applied; the web copy is told to take a fresh snapshot.
        \Sofrexa\Core\Migrator::run();
        Settings::flush();
        Db::exec("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('epoch', ?)", [bin2hex(random_bytes(8))]);
        Audit::log('backup.restore', date('d.m.Y H:i', intdiv((int) $manifest['created_at'], 1000)) . ' · ' . basename($zipPath), 'backup', basename($zipPath), ['safety' => basename($safety)], $actor);
        return basename($safety);
    }

    /** Deletes backups older than backup.keep_days (safety copies too), keeping at least the newest three. */
    public static function prune(): void
    {
        $keep = max(1, (int) Settings::get('backup.keep_days', 30));
        $limit = Clock::ms() - $keep * 86_400_000;
        foreach (array_slice(self::list(), 3) as $b) {
            if ($b['at'] < $limit) {
                @unlink(self::dir() . '/' . $b['file']);
                @unlink(self::dir() . '/' . substr($b['file'], 0, -4) . '.json');
            }
        }
    }

    public static function addPlace(string $file, string $place): void
    {
        $zip = self::path($file);
        $meta = json_arr(@file_get_contents(substr($zip, 0, -4) . '.json') ?: null);
        $meta['places'] = array_values(array_unique([...($meta['places'] ?? ['pc']), $place]));
        self::writeMeta($zip, $meta);
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => I18n::num($bytes / 1_048_576, $bytes >= 104_857_600 ? 0 : 1) . ' MB',
            $bytes >= 1024 => I18n::num($bytes / 1024) . ' KB',
            default => I18n::num($bytes) . ' B',
        };
    }

    private static function writeMeta(string $zipPath, array $meta): void
    {
        file_put_contents(substr($zipPath, 0, -4) . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
