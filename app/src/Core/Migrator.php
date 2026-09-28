<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Applies app/migrations/NNN_name.sql files in order, once each. */
final class Migrator
{
    public static function run(?callable $say = null): int
    {
        Db::exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
        $done = array_flip(array_column(Db::rows('SELECT name FROM schema_migrations'), 'name'));
        $files = glob(APP_DIR . '/migrations/*.sql') ?: [];
        sort($files);
        $count = 0;
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if (isset($done[$name])) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            Db::tx(static function () use ($sql, $name): void {
                Db::pdo()->exec($sql);
                Db::insert('schema_migrations', ['name' => $name, 'applied_at' => Clock::ms()]);
            });
            $say && $say("migrated $name");
            $count++;
        }
        return $count;
    }

    public static function pending(): bool
    {
        if (!Db::value("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")) {
            return true;
        }
        $done = (int) Db::value('SELECT COUNT(*) FROM schema_migrations');
        return $done < count(glob(APP_DIR . '/migrations/*.sql') ?: []);
    }
}
