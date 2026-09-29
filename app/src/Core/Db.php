<?php
declare(strict_types=1);

namespace Sofrexa\Core;

use PDO;

/**
 * Thin PDO wrapper around SQLite (WAL). All queries are parameterised.
 * Tables listed in Sync::TABLES get their changes recorded in sync_outbox by save()/delete().
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect((string) App::config('db'));
        }
        return self::$pdo;
    }

    public static function connect(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo = $pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Column → value map keyed by the first column. */
    public static function pairs(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(',', array_map([self::class, 'ident'], $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::exec($sql, array_values(array_map([self::class, 'cell'], $row)));
    }

    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(',', array_map(static fn(string $c): string => self::ident($c) . ' = ?', array_keys($row)));
        return self::exec(
            sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), $set, $where),
            [...array_values(array_map([self::class, 'cell'], $row)), ...$params]
        );
    }

    /**
     * Insert or update a synced record by id. Sets updated_at and records the change for sync.
     * Returns the id.
     */
    public static function save(string $table, array $row): string
    {
        $row['id'] ??= Uuid::v7();
        // a person's pay terms as they were, for the history kept where the change is made (decision 52)
        $terms = $table === 'users' ? \Sofrexa\Modules\Staff\Staff::termsBefore($row) : false;
        // Last write wins between the PC and the web copy: an edit must beat the version it replaces,
        // even when this machine's clock runs a little behind the one that wrote that version.
        $current = self::value('SELECT updated_at FROM ' . self::ident($table) . ' WHERE id = ?', [$row['id']]);
        $exists = $current !== null;
        $row['updated_at'] = max(Clock::ms(), (int) $current + 1);
        if ($exists) {
            $id = $row['id'];
            unset($row['id']);
            self::update($table, $row, 'id = ?', [$id]);
            $row['id'] = $id;
        } else {
            self::insert($table, $row);
        }
        Sync::touch($table, $row['id']);
        if ($terms !== false) {
            \Sofrexa\Modules\Staff\Staff::recordTerms($row['id'], $terms);
        }
        return $row['id'];
    }

    /** Soft delete of a synced record (kept for history and sync). */
    public static function softDelete(string $table, string $id): void
    {
        $current = (int) self::value('SELECT updated_at FROM ' . self::ident($table) . ' WHERE id = ?', [$id]);
        self::update($table, ['deleted' => 1, 'updated_at' => max(Clock::ms(), $current + 1)], 'id = ?', [$id]);
        Sync::touch($table, $id);
    }

    /** Append-only insert that is also replicated. */
    public static function append(string $table, array $row): string
    {
        $row['id'] ??= Uuid::v7();
        self::insert($table, $row);
        Sync::touch($table, $row['id']);
        return $row['id'];
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Bad identifier: $name");
        }
        return '"' . $name . '"';
    }

    private static function cell(mixed $v): mixed
    {
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $v;
    }

    /** "?,?,?" for an IN (...) list. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }
}
