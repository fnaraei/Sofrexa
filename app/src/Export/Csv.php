<?php
declare(strict_types=1);

namespace Sofrexa\Export;

/**
 * CSV for Excel (BOM, semicolons). A text cell that starts like a formula (= + - @, a tab or a line break) is written
 * with a leading apostrophe, so a name such as "=HYPERLINK(…)" opens as the text it is (audit 8, S04). Numbers — a
 * negative amount like "-12,50" too — are left as they are.
 */
final class Csv
{
    /** @param list<list<scalar|null>> $rows */
    public static function build(array $head, iterable $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, array_map([self::class, 'cell'], $head), ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, array_map([self::class, 'cell'], $r), ';', '"', '');
        }
        rewind($h);
        return (string) stream_get_contents($h);
    }

    public static function cell(mixed $v): string
    {
        $s = (string) $v;
        if ($s !== '' && str_contains("=+-@\t\r\n", $s[0]) && !preg_match('/^[-+]?[\d.,]+%?$/', $s)) {
            return "'" . $s;
        }
        return $s;
    }
}
