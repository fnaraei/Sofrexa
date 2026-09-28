<?php
declare(strict_types=1);

namespace Sofrexa\Export;

/**
 * Minimal Office Open XML (.xlsx) writer: one or more sheets, a bold header row, numbers stay numbers,
 * money columns get a "#,##0.00" format. Enough for accountant exports; no external library.
 *
 *   (new Xlsx())->sheet('Log', ['Saat', 'Tutar'], [['21:42', 870.0]], moneyCols: [1])->download('log.xlsx');
 */
final class Xlsx
{
    private array $sheets = [];

    /** @param int[] $moneyCols zero-based column indexes shown as money */
    public function sheet(string $name, array $header, iterable $rows, array $moneyCols = [], array $widths = []): self
    {
        $this->sheets[] = ['name' => mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $name) ?? 'Sheet', 0, 31), 'header' => $header, 'rows' => $rows, 'money' => $moneyCols, 'widths' => $widths];
        return $this;
    }

    public function save(string $file): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot write $file");
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn(int $i): string => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(fn(int $i): string => '<sheet name="' . self::x($this->sheets[$i - 1]['name']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>', range(1, $n)))
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn(int $i): string => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, $n)))
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // styles: 0 default, 1 bold header, 2 money, 3 number
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3ECDF"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="4"><xf xfId="0"/><xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf xfId="0" numFmtId="164" applyNumberFormat="1"/><xf xfId="0" numFmtId="3" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        foreach ($this->sheets as $i => $sh) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sh));
        }
        $zip->close();
    }

    public function download(string $filename): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sfx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function sheetXml(array $sh): string
    {
        $cols = '';
        foreach ($sh['header'] as $c => $h) {
            $w = $sh['widths'][$c] ?? max(10, min(60, mb_strlen((string) $h) + 4));
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>';
        $xml .= $this->row(1, $sh['header'], [], 1);
        $r = 1;
        foreach ($sh['rows'] as $row) {
            $xml .= $this->row(++$r, array_values($row), $sh['money'], 0);
        }
        return $xml . '</sheetData><autoFilter ref="A1:' . self::col(count($sh['header']) - 1) . max(1, $r) . '"/></worksheet>';
    }

    private function row(int $r, array $cells, array $money, int $style): string
    {
        $out = '<row r="' . $r . '">';
        foreach ($cells as $c => $v) {
            $ref = self::col($c) . $r;
            if ($v === null || $v === '') {
                continue;
            }
            if ((is_int($v) || is_float($v)) && $style === 0) {
                $out .= '<c r="' . $ref . '" s="' . (in_array($c, $money, true) ? 2 : 3) . '"><v>' . $v . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
