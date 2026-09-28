<?php
declare(strict_types=1);

namespace Sofrexa\Export;

/**
 * Minimal PDF writer for reports (A4, text, key/value blocks, tables with a repeated header, page numbers).
 * No external library: a TrueType font of the system (Arial on Windows, DejaVu Sans on Linux, or config pdf.font)
 * is embedded as a CID font, so Turkish, Cyrillic and every other Latin letter print as they are. Without a font
 * file it falls back to Helvetica and plain ASCII. Bold is drawn with a stroked fill (one font file per document).
 *
 *   $pdf = new Pdf('Gün sonu · 27 Eylül 2026', 'Basilic');
 *   $pdf->heading('Gün sonu')->kv([['Ciro', '₺52.140']])->table([['Ürün', 3], ['Tutar', 1, 'R']], $rows);
 *   file_put_contents('z.pdf', $pdf->output());
 */
final class Pdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 40.0;

    private array $pages = [];
    private string $cur = '';
    private float $y = 0;
    private ?array $font = null;
    private array $used = [];

    public function __construct(private string $title = '', private string $footer = '')
    {
        $this->font = self::loadFont();
        $this->page();
    }

    // ------------------------------------------------------------ building

    public function heading(string $text, float $size = 18, string $sub = ''): self
    {
        $this->need($size + 20);
        $this->y -= $size;
        $this->text(self::M, $this->y, $text, $size, true);
        if ($sub !== '') {
            $this->y -= 14;
            $this->text(self::M, $this->y, $sub, 9.5, false, [0.35, 0.38, 0.36]);
        }
        $this->y -= 12;
        return $this;
    }

    public function section(string $text): self
    {
        $this->need(34);
        $this->y -= 16;
        $this->text(self::M, $this->y, $text, 12, true);
        $this->y -= 6;
        return $this;
    }

    public function paragraph(string $text, float $size = 9.5): self
    {
        foreach ($this->wrap($text, $size, self::W - 2 * self::M) as $line) {
            $this->need($size + 4);
            $this->y -= $size + 3;
            $this->text(self::M, $this->y, $line, $size, false, [0.35, 0.38, 0.36]);
        }
        $this->y -= 4;
        return $this;
    }

    /** Label / value rows in $cols columns; a row [label, value, true] is bold. */
    public function kv(array $rows, int $cols = 1): self
    {
        $colW = (self::W - 2 * self::M - ($cols - 1) * 24) / $cols;
        foreach (array_chunk($rows, $cols) as $line) {
            $this->need(18);
            $this->y -= 15;
            foreach ($line as $i => $r) {
                $x = self::M + $i * ($colW + 24);
                $bold = !empty($r[2]);
                $this->text($x, $this->y, (string) $r[0], 10, $bold, $bold ? [0.09, 0.13, 0.11] : [0.28, 0.33, 0.29]);
                $v = (string) $r[1];
                $this->text($x + $colW - $this->width($v, 10), $this->y, $v, 10, $bold);
            }
        }
        $this->y -= 8;
        return $this;
    }

    /**
     * @param array $cols [[title, weight, 'L'|'R'], …]
     * @param array $rows rows of strings; a row with key '_bold' => true is bold, '_fill' => true shaded
     */
    public function table(array $cols, array $rows, float $size = 8.5): self
    {
        $total = array_sum(array_column($cols, 1));
        $w = self::W - 2 * self::M;
        $xs = [];
        $x = self::M;
        foreach ($cols as $c) {
            $cw = $w * $c[1] / $total;
            $xs[] = [$x, $cw, $c[2] ?? 'L'];
            $x += $cw;
        }
        $rowH = $size + 8;
        $header = function () use ($cols, $xs, $size, $rowH, $w): void {
            $this->need($rowH * 2);
            $this->rect(self::M, $this->y - $rowH, $w, $rowH, [0.95, 0.93, 0.89]);
            foreach ($cols as $i => $c) {
                $this->cell($xs[$i], $this->y - $rowH + 5, mb_strtoupper(strtr((string) $c[0], ['i' => 'İ', 'ı' => 'I']), 'UTF-8'), $size - 1, true, [0.45, 0.48, 0.46]); // Turkish capitals
            }
            $this->y -= $rowH;
        };
        $header();
        foreach ($rows as $r) {
            if ($this->y - $rowH < self::M + 20) {
                $this->page();
                $header();
            }
            $bold = !empty($r['_bold']);
            if (!empty($r['_fill'])) {
                $this->rect(self::M, $this->y - $rowH, $w, $rowH, [0.97, 0.96, 0.93]);
            }
            foreach (array_values(array_filter($r, static fn($k): bool => !is_string($k) || $k[0] !== '_', ARRAY_FILTER_USE_KEY)) as $i => $v) {
                if (isset($xs[$i])) {
                    $this->cell($xs[$i], $this->y - $rowH + 5, (string) $v, $size, $bold);
                }
            }
            $this->line(self::M, $this->y - $rowH, self::M + $w, $this->y - $rowH, [0.88, 0.86, 0.82]);
            $this->y -= $rowH;
        }
        $this->y -= 10;
        return $this;
    }

    public function space(float $pt = 10): self
    {
        $this->y -= $pt;
        return $this;
    }

    // ------------------------------------------------------------ output

    public function output(): string
    {
        $this->flush();
        $objs = [];
        $add = static function (string $body) use (&$objs): int {
            $objs[] = $body;
            return count($objs);
        };
        $catalog = $add('');
        $pagesId = $add('');
        $fontId = $this->font ? $this->embedFont($add) : $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $kids = [];
        $n = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $content .= $this->footerOps($i + 1, $n);
            $z = gzcompress($content, 6);
            $cid = $add("<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream");
            $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 " . self::W . ' ' . self::H . "] /Resources << /Font << /F1 $fontId 0 R >> >> /Contents $cid 0 R >>");
        }
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $k): string => "$k 0 R", $kids)) . "] /Count $n >>";
        $info = $add('<< /Title ' . self::utf16($this->title) . ' /Producer (Sofrexa) /CreationDate (D:' . date('YmdHis') . ') >>');
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    // ------------------------------------------------------------ drawing

    private function page(): void
    {
        $this->flush();
        $this->cur = '';
        $this->y = self::H - self::M;
        $this->pages[] = null;
    }

    private function flush(): void
    {
        if ($this->pages && end($this->pages) === null) {
            $this->pages[array_key_last($this->pages)] = $this->cur;
        }
    }

    private function need(float $h): void
    {
        if ($this->y - $h < self::M + 20) {
            $this->page();
        }
    }

    private function cell(array $col, float $y, string $text, float $size, bool $bold, array $rgb = [0.09, 0.13, 0.11]): void
    {
        [$x, $w, $align] = $col;
        $text = $this->fit($text, $size, $w - 8);
        $tx = $align === 'R' ? $x + $w - 4 - $this->width($text, $size) : $x + 4;
        $this->text($tx, $y, $text, $size, $bold, $rgb);
    }

    private function text(float $x, float $y, string $text, float $size, bool $bold = false, array $rgb = [0.09, 0.13, 0.11]): void
    {
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg %s /F1 %.2F Tf %.2F %.2F Td %s Tj ET\n", $rgb[0], $rgb[1], $rgb[2],
            $bold ? sprintf('2 Tr %.2F w %.3F %.3F %.3F RG', $size * 0.035, $rgb[0], $rgb[1], $rgb[2]) : '0 Tr', $size, $x, $y, $this->encode($text));
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F RG 0.5 w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $x1, $y1, $x2, $y2);
    }

    private function footerOps(int $i, int $n): string
    {
        $t = trim($this->footer . ' · ' . $i . ' / ' . $n, ' ·');
        $save = $this->cur;
        $this->cur = '';
        $this->text(self::M, 22, $this->title, 7.5, false, [0.55, 0.57, 0.55]);
        $this->text(self::W - self::M - $this->width($t, 7.5), 22, $t, 7.5, false, [0.55, 0.57, 0.55]);
        $ops = $this->cur;
        $this->cur = $save;
        return $ops;
    }

    private function fit(string $text, float $size, float $max): string
    {
        if ($this->width($text, $size) <= $max) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->width($text . '…', $size) > $max) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    private function wrap(string $text, float $size, float $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->width($try, $size) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        return $line !== '' ? [...$lines, $line] : $lines;
    }

    // ------------------------------------------------------------ fonts

    private function width(string $text, float $size): float
    {
        if (!$this->font) {
            return mb_strlen($text) * $size * 0.52;
        }
        $w = 0;
        foreach (mb_str_split($text) as $ch) {
            $gid = $this->gid(mb_ord($ch));
            $w += $this->font['adv'][$gid] ?? $this->font['adv'][count($this->font['adv']) - 1];
        }
        return $w * $size / $this->font['upm'];
    }

    private function encode(string $text): string
    {
        if (!$this->font) {
            $plain = strtr($text, ['ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', '₺' => 'TL ', '−' => '-', '…' => '...', '·' => '-', '’' => "'", '–' => '-']);
            $plain = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $plain);
            return '(' . strtr($plain, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }
        $hex = '';
        foreach (mb_str_split($text) as $ch) {
            $cp = mb_ord($ch);
            $gid = $this->gid($cp);
            $this->used[$gid] = $cp;
            $hex .= sprintf('%04X', $gid);
        }
        return '<' . $hex . '>';
    }

    private function gid(int $cp): int
    {
        $f = &$this->font;
        if (isset($f['cache'][$cp])) {
            return $f['cache'][$cp];
        }
        $g = 0;
        foreach ($f['segs'] as [$start, $end, $delta, $rangeOffset, $i]) {
            if ($cp >= $start && $cp <= $end) {
                if ($rangeOffset === 0) {
                    $g = ($cp + $delta) & 0xFFFF;
                } else {
                    $pos = $f['cmapIro'] + $i * 2 + $rangeOffset + ($cp - $start) * 2;
                    $raw = unpack('n', substr($f['data'], $pos, 2))[1] ?? 0;
                    $g = $raw ? ($raw + $delta) & 0xFFFF : 0;
                }
                break;
            }
        }
        return $f['cache'][$cp] = $g;
    }

    private function embedFont(callable $add): int
    {
        $f = $this->font;
        $name = '/' . preg_replace('/[^A-Za-z0-9]/', '', $f['name'] ?: 'SofrexaSans');
        $scale = 1000 / $f['upm'];
        $z = gzcompress($f['data'], 6);
        $file = $add('<< /Length ' . strlen($z) . ' /Length1 ' . strlen($f['data']) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream");
        $bbox = implode(' ', array_map(static fn(int $v): string => (string) round($v * $scale), $f['bbox']));
        $desc = $add("<< /Type /FontDescriptor /FontName $name /Flags 32 /FontBBox [$bbox] /ItalicAngle 0 /Ascent " . round($f['asc'] * $scale)
            . ' /Descent ' . round($f['desc'] * $scale) . ' /CapHeight ' . round($f['asc'] * $scale) . " /StemV 80 /FontFile2 $file 0 R >>");
        ksort($this->used);
        $w = '';
        foreach ($this->used as $gid => $cp) {
            $w .= $gid . ' [' . round(($f['adv'][$gid] ?? end($f['adv'])) * $scale) . '] ';
        }
        $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont $name /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $desc 0 R /W [$w] /CIDToGIDMap /Identity >>");
        $map = '';
        foreach (array_chunk($this->used, 100, true) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $cp) {
                $u = mb_convert_encoding(mb_chr($cp), 'UTF-16BE', 'UTF-8');
                $map .= sprintf("<%04X> <%s>\n", $gid, strtoupper(bin2hex($u)));
            }
            $map .= "endbfchar\n";
        }
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $map . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $tu = $add('<< /Length ' . strlen($cmap) . " >>\nstream\n" . $cmap . "\nendstream");
        return $add("<< /Type /Font /Subtype /Type0 /BaseFont $name /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $tu 0 R >>");
    }

    private static function utf16(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /** Parses the TrueType tables needed: head, hhea, hmtx, cmap (format 4), name. */
    private static function loadFont(): ?array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache ?: null;
        }
        $paths = array_filter([(string) \Sofrexa\Core\App::config('pdf.font', ''), 'C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf', '/Library/Fonts/Arial.ttf', '/System/Library/Fonts/Supplemental/Arial.ttf']);
        foreach ($paths as $p) {
            if (!is_file($p) || !is_readable($p)) {
                continue;
            }
            try {
                return $cache = self::parse((string) file_get_contents($p));
            } catch (\Throwable) {
                continue;
            }
        }
        $cache = [];
        return null;
    }

    private static function parse(string $d): array
    {
        $num = unpack('n', substr($d, 4, 2))[1];
        $t = [];
        for ($i = 0; $i < $num; $i++) {
            $r = unpack('a4tag/Nsum/Noff/Nlen', substr($d, 12 + $i * 16, 16));
            $t[$r['tag']] = $r['off'];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $need) {
            if (!isset($t[$need])) {
                throw new \RuntimeException("font: no $need");
            }
        }
        $s16 = static fn(int $o): int => ($v = unpack('n', substr($d, $o, 2))[1]) >= 0x8000 ? $v - 0x10000 : $v;
        $u16 = static fn(int $o): int => unpack('n', substr($d, $o, 2))[1];
        $upm = $u16($t['head'] + 18);
        $bbox = [$s16($t['head'] + 36), $s16($t['head'] + 38), $s16($t['head'] + 40), $s16($t['head'] + 42)];
        $asc = $s16($t['hhea'] + 4);
        $desc = $s16($t['hhea'] + 6);
        $nh = $u16($t['hhea'] + 34);
        $adv = array_values(unpack('n*', substr($d, $t['hmtx'], $nh * 4)));
        $adv = array_values(array_filter($adv, static fn(int $k): bool => $k % 2 === 0, ARRAY_FILTER_USE_KEY));
        // cmap: Windows Unicode BMP, format 4
        $c = $t['cmap'];
        $sub = null;
        for ($i = 0, $n = $u16($c + 2); $i < $n; $i++) {
            [$pid, $eid, $off] = [$u16($c + 4 + $i * 8), $u16($c + 6 + $i * 8), unpack('N', substr($d, $c + 8 + $i * 8, 4))[1]];
            if (($pid === 3 && $eid === 1) || ($pid === 0 && $sub === null)) {
                if ($u16($c + $off) === 4) {
                    $sub = $c + $off;
                }
            }
        }
        if ($sub === null) {
            throw new \RuntimeException('font: no format 4 cmap');
        }
        $segX2 = $u16($sub + 6);
        $ends = $sub + 14;
        $starts = $ends + $segX2 + 2;
        $deltas = $starts + $segX2;
        $iro = $deltas + $segX2;
        $segs = [];
        for ($i = 0; $i < $segX2 / 2; $i++) {
            $segs[] = [$u16($starts + $i * 2), $u16($ends + $i * 2), $s16($deltas + $i * 2), $u16($iro + $i * 2), $i];
        }
        $name = 'SofrexaSans';
        if (isset($t['name'])) {
            $nt = $t['name'];
            $count = $u16($nt + 2);
            $strings = $nt + $u16($nt + 4);
            for ($i = 0; $i < $count; $i++) {
                $r = $nt + 6 + $i * 12;
                if ($u16($r + 6) === 6 && $u16($r) === 3) {
                    $name = (string) mb_convert_encoding(substr($d, $strings + $u16($r + 10), $u16($r + 8)), 'UTF-8', 'UTF-16BE');
                    break;
                }
            }
        }
        return ['data' => $d, 'upm' => $upm, 'bbox' => $bbox, 'asc' => $asc, 'desc' => $desc, 'adv' => $adv, 'segs' => $segs, 'cmapIro' => $iro, 'name' => $name, 'cache' => []];
    }
}
