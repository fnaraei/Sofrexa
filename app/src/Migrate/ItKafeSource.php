<?php
declare(strict_types=1);

namespace Sofrexa\Migrate;

/**
 * Reads the ItKafe SQL Server database with sqlcmd (PHP has no SQL Server driver here, and the PC's antivirus blocks
 * scripts that load the .NET client). Each query is wrapped so that sqlcmd writes one record per line in UTF-8:
 * fields joined by U+001F, the record closed by U+001E, line breaks inside values turned into spaces.
 * Everything stays on this machine: the files go to storage/tmp and are removed after reading.
 */
final class ItKafeSource
{
    private const SEP = "\x1f";
    private const END = "\x1e";

    public function __construct(private string $server = 'localhost\SQLEXPRESS', private string $database = 'ItKafe', private ?string $sqlcmd = null)
    {
        $this->sqlcmd ??= self::findSqlcmd();
    }

    public static function findSqlcmd(): string
    {
        foreach (['C:/Program Files/Microsoft SQL Server/Client SDK/ODBC/170/Tools/Binn/SQLCMD.EXE', 'C:/Program Files/Microsoft SQL Server/Client SDK/ODBC/180/Tools/Binn/SQLCMD.EXE',
            'C:/Program Files/Microsoft SQL Server/Client SDK/ODBC/130/Tools/Binn/SQLCMD.EXE', 'C:/Program Files/Microsoft SQL Server/110/Tools/Binn/SQLCMD.EXE'] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return 'sqlcmd';
    }

    /**
     * Rows of a SELECT as associative arrays of strings (NULL comes back as '').
     * $sql must name its columns; dates should be converted in the query (CONVERT(varchar(23), col, 126)).
     *
     * @return \Generator<int, array<string, string>>
     */
    public function rows(string $sql, array $columns): \Generator
    {
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = "REPLACE(REPLACE(ISNULL(CONVERT(nvarchar(max), q.[$c]), N''), NCHAR(13), N' '), NCHAR(10), N' ')";
        }
        $wrapped = 'SET NOCOUNT ON; SELECT CONCAT(' . implode(', NCHAR(31), ', $parts) . ', NCHAR(30)) AS r FROM (' . $sql . ') q';
        $out = \Sofrexa\Core\App::storage('tmp') . '/itk-' . bin2hex(random_bytes(6)) . '.txt';
        $qfile = $out . '.sql';
        file_put_contents($qfile, "\xEF\xBB\xBF" . $wrapped);
        // backslash paths: sqlcmd takes a leading "/" for a switch
        $cmd = escapeshellarg($this->sqlcmd) . ' -S ' . escapeshellarg($this->server) . ' -E -d ' . escapeshellarg($this->database)
            . ' -b -y 0 -f 65001 -i ' . escapeshellarg(str_replace('/', '\\', $qfile)) . ' -o ' . escapeshellarg(str_replace('/', '\\', $out)) . ' 2>&1';
        exec($cmd, $lines, $code);
        @unlink($qfile);
        if ($code !== 0 || !is_file($out)) {
            @unlink($out);
            throw new \RuntimeException('sqlcmd failed (' . $code . '): ' . trim(implode(' ', $lines)) . ' — query: ' . mb_substr($sql, 0, 200));
        }
        $h = fopen($out, 'rb');
        try {
            $first = true;
            $n = count($columns);
            while (($line = fgets($h)) !== false) {
                if ($first) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                    $first = false;
                }
                $line = rtrim($line, "\r\n");
                if ($line === '' || !str_ends_with($line, self::END)) {
                    if (str_starts_with(ltrim($line), 'Msg ') || str_contains($line, 'Sqlcmd: Error')) {
                        throw new \RuntimeException('sqlcmd: ' . $line);
                    }
                    continue;
                }
                $vals = explode(self::SEP, substr($line, 0, -1));
                if (count($vals) !== $n) {
                    throw new \RuntimeException('unexpected field count ' . count($vals) . ' / ' . $n . ' in: ' . mb_substr($line, 0, 120));
                }
                yield array_combine($columns, $vals);
            }
        } finally {
            fclose($h);
            @unlink($out);
        }
    }

    /** One value (first column of the first row). */
    public function value(string $sql): string
    {
        foreach ($this->rows($sql, ['v']) as $r) {
            return $r['v'];
        }
        return '';
    }
}
