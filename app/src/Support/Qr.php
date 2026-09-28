<?php
declare(strict_types=1);

namespace Sofrexa\Support;

/**
 * QR code encoder (ISO/IEC 18004): byte mode, error correction level M, versions 1–10
 * (up to 213 bytes — table links are about 60). Follows the reference algorithm by Project Nayuki.
 * Output: SVG for screens, PNG for downloads. Thermal printers draw QR codes natively (EscPos::qr).
 */
final class Qr
{
    /** Level M, versions 1..10: error-correction codewords per block, number of blocks. */
    private const ECC = [1 => [10, 1], [16, 1], [26, 1], [18, 2], [24, 2], [16, 4], [18, 4], [22, 4], [22, 5], [26, 5]];

    /** @var bool[][] */
    private array $m = [];
    /** @var bool[][] */
    private array $fn = [];
    private int $size;
    private int $version;

    public static function matrix(string $text): array
    {
        $q = new self($text);
        return $q->m;
    }

    private function __construct(string $data)
    {
        $len = strlen($data);
        for ($v = 1; $v <= 10; $v++) {
            $cap = self::dataCodewords($v) * 8;
            if (4 + ($v < 10 ? 8 : 16) + $len * 8 <= $cap) {
                break;
            }
        }
        if ($v > 10) {
            throw new \InvalidArgumentException('QR text too long');
        }
        $this->version = $v;
        $this->size = $v * 4 + 17;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->m;

        // data bits
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(4, 4);
        $push($len, $v < 10 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) {
            $push(ord($data[$i]), 8);
        }
        $capBits = self::dataCodewords($v) * 8;
        $push(0, min(4, $capBits - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bits[$i + $j];
            }
            $codewords[] = $b;
        }
        for ($pad = 0xEC; count($codewords) < self::dataCodewords($v); $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        $this->functionPatterns();
        $this->drawCodewords($this->interleave($codewords));
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->formatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $best = $mask;
                $bestScore = $score;
            }
            $this->applyMask($mask);
        }
        $this->applyMask($best);
        $this->formatBits($best);
    }

    private static function rawModules(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $n = intdiv($v, 7) + 2;
            $r -= (25 * $n - 10) * $n - 55;
            if ($v >= 7) {
                $r -= 36;
            }
        }
        return $r;
    }

    private static function dataCodewords(int $v): int
    {
        [$ecc, $blocks] = self::ECC[$v];
        return intdiv(self::rawModules($v), 8) - $ecc * $blocks;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function functionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->formatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(): array
    {
        $v = $this->version;
        if ($v === 1) {
            return [];
        }
        $n = intdiv($v, 7) + 2;
        $step = (int) (ceil(($v * 4 + 4) / ($n * 2 - 2)) * 2);
        $res = [6];
        for ($pos = $this->size - 7; count($res) < $n; $pos -= $step) {
            array_splice($res, 1, 0, [$pos]);
        }
        return $res;
    }

    private function formatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private function interleave(array $data): array
    {
        [$eccLen, $numBlocks] = self::ECC[$this->version];
        $raw = intdiv(self::rawModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        // divisor
        $div = array_fill(0, $eccLen, 0);
        $div[$eccLen - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $eccLen; $i++) {
            for ($j = 0; $j < $eccLen; $j++) {
                $div[$j] = self::gfMul($div[$j], $root);
                if ($j + 1 < $eccLen) {
                    $div[$j] ^= $div[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $n = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $n);
            $k += $n;
            $rem = array_fill(0, $eccLen, 0);
            foreach ($dat as $b) {
                $f = $b ^ array_shift($rem);
                $rem[] = 0;
                for ($j = 0; $j < $eccLen; $j++) {
                    $rem[$j] ^= self::gfMul($div[$j], $f);
                }
            }
            if ($i < $numShort) {
                $dat[] = -1; // placeholder, skipped when interleaving
            }
            $blocks[] = [...$dat, ...$rem];
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $out[] = $b[$i];
                }
            }
        }
        return $out;
    }

    private function drawCodewords(array $cw): void
    {
        $s = $this->size;
        $total = count($cw) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $s - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $inv = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inv && !$this->fn[$y][$x]) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** Penalty rules 1 (runs), 2 (2×2 blocks), 3 (finder-like patterns) and 4 (dark balance). */
    private function penalty(): int
    {
        $s = $this->size;
        $p = 0;
        $dark = 0;
        foreach ([false, true] as $vertical) {
            for ($a = 0; $a < $s; $a++) {
                $run = 0;
                $prev = null;
                $line = '';
                for ($b = 0; $b < $s; $b++) {
                    $c = $vertical ? $this->m[$b][$a] : $this->m[$a][$b];
                    $line .= $c ? '1' : '0';
                    if ($c === $prev) {
                        $run++;
                        if ($run === 5) {
                            $p += 3;
                        } elseif ($run > 5) {
                            $p++;
                        }
                    } else {
                        $run = 1;
                        $prev = $c;
                    }
                }
                $p += 40 * (substr_count($line, '00001011101') + substr_count($line, '10111010000'));
            }
        }
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $c = $this->m[$y][$x];
                if ($c) {
                    $dark++;
                }
                if ($x < $s - 1 && $y < $s - 1 && $c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        $total = $s * $s;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $p + max(0, $k) * 10;
    }

    // ------------------------------------------------------------ output

    /** SVG with a quiet zone of 4 modules; $color defaults to currentColor. */
    public static function svg(string $text, int $px = 200, string $color = 'currentColor'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $d = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $v = $n + 8;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . $v . ' ' . $v . '" shape-rendering="crispEdges" role="img" aria-label="QR"><path fill="' . htmlspecialchars($color) . '" d="' . $d . '"/></svg>';
    }

    /** PNG bytes (black on white), $scale pixels per module. */
    public static function png(string $text, int $scale = 12): string
    {
        $m = self::matrix($text);
        $n = count($m) + 8;
        $img = imagecreate($n * $scale, $n * $scale);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        foreach ($m as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    imagefilledrectangle($img, ($x + 4) * $scale, ($y + 4) * $scale, ($x + 5) * $scale - 1, ($y + 5) * $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }
}
