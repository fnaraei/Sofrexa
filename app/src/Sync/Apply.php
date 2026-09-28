<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{Db, Settings, Sync};

/**
 * Reading outgoing changes and applying incoming ones. Mutable rows: last write wins on updated_at.
 * Append-only rows: inserted once, never changed. Applied rows are not recorded again (Sync::muted).
 */
final class Apply
{
    private static array $columns = [];

    /** Current state of the rows in outbox entries, deduplicated (table, id). */
    public static function collect(array $outbox): array
    {
        $byTable = [];
        foreach ($outbox as $o) {
            $byTable[$o['tbl']][$o['row_id']] = true;
        }
        $rows = [];
        foreach ($byTable as $table => $ids) {
            if (!Sync::isReplicated($table)) {
                continue;
            }
            foreach (array_chunk(array_keys($ids), 400) as $chunk) {
                foreach (Db::rows('SELECT * FROM ' . Db::ident($table) . ' WHERE id IN (' . Db::in($chunk) . ')', $chunk) as $r) {
                    $rows[] = ['t' => $table, 'r' => $r];
                }
            }
        }
        return $rows;
    }

    /** Applies incoming rows. Returns the number that changed something. */
    public static function rows(array $rows): int
    {
        $n = 0;
        Sync::muted(static function () use ($rows, &$n): void {
            Db::tx(static function () use ($rows, &$n): void {
                foreach ($rows as $item) {
                    $table = (string) ($item['t'] ?? '');
                    $row = (array) ($item['r'] ?? []);
                    if (!Sync::isReplicated($table) || empty($row['id'])) {
                        continue;
                    }
                    $n += in_array($table, Sync::APPEND, true) ? self::insertOnce($table, $row) : self::upsert($table, $row);
                }
            });
        });
        Settings::flush();
        return $n;
    }

    private static function cols(string $table): array
    {
        return self::$columns[$table] ??= array_column(Db::rows('PRAGMA table_info(' . Db::ident($table) . ')'), 'name');
    }

    private static function insertOnce(string $table, array $row): int
    {
        $row = array_intersect_key($row, array_flip(self::cols($table)));
        $cols = array_keys($row);
        return Db::exec('INSERT OR IGNORE INTO ' . Db::ident($table) . ' (' . implode(',', array_map([Db::class, 'ident'], $cols)) . ') VALUES (' . Db::in($cols) . ')', array_values($row));
    }

    private static function upsert(string $table, array $row): int
    {
        $row = array_intersect_key($row, array_flip(self::cols($table)));
        $cols = array_keys($row);
        $set = implode(',', array_map(static fn(string $c): string => Db::ident($c) . ' = excluded.' . Db::ident($c), array_diff($cols, ['id'])));
        return Db::exec('INSERT INTO ' . Db::ident($table) . ' (' . implode(',', array_map([Db::class, 'ident'], $cols)) . ') VALUES (' . Db::in($cols) . ')
            ON CONFLICT(id) DO UPDATE SET ' . $set . ' WHERE excluded.updated_at > ' . Db::ident($table) . '.updated_at', array_values($row));
    }
}
