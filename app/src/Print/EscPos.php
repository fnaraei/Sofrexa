<?php
declare(strict_types=1);

namespace Sofrexa\Print;

/**
 * ESC/POS byte builder for 80 mm (48 columns) and 58 mm (32 columns) thermal printers.
 * Text is converted to code page 857 (Turkish); characters it cannot hold are transliterated.
 */
final class EscPos
{
    private string $buf = '';
    public readonly int $cols;

    public function __construct(int $paperMm = 80)
    {
        $this->cols = $paperMm === 58 ? 32 : 48;
        $this->buf = "\x1B\x40" . "\x1B\x74\x0D"; // init, code page 13 = PC857 Turkish
    }

    public static function encode(string $s): string
    {
        $s = strtr($s, ['₺' => 'TL', '—' => '-', '–' => '-', '·' => '-', '…' => '...', '“' => '"', '”' => '"', '’' => "'", '×' => 'x', '→' => '>', '−' => '-']);
        $out = @iconv('UTF-8', 'CP857//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '' : $out;
    }

    public function raw(string $bytes): self
    {
        $this->buf .= $bytes;
        return $this;
    }

    public function text(string $s = ''): self
    {
        $this->buf .= self::encode($s) . "\n";
        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->buf .= "\x1B\x45" . ($on ? "\x01" : "\x00");
        return $this;
    }

    /** l | c | r */
    public function align(string $a): self
    {
        $this->buf .= "\x1B\x61" . ['l' => "\x00", 'c' => "\x01", 'r' => "\x02"][$a];
        return $this;
    }

    /** Character size multipliers 1–4. */
    public function size(int $w = 1, int $h = 1): self
    {
        $this->buf .= "\x1D\x21" . chr((($w - 1) << 4) | ($h - 1));
        return $this;
    }

    public function invert(bool $on = true): self
    {
        $this->buf .= "\x1D\x42" . ($on ? "\x01" : "\x00");
        return $this;
    }

    /** Left text and right text on one line, padded to the paper width (at the current size multiplier). */
    public function pair(string $left, string $right, int $mult = 1): self
    {
        $cols = intdiv($this->cols, $mult);
        $right = self::encode($right);
        $left = self::encode($left);
        $space = $cols - strlen($right) - 1;
        if (strlen($left) > $space) {
            $this->buf .= $left . "\n" . str_pad($right, $cols, ' ', STR_PAD_LEFT) . "\n";
        } else {
            $this->buf .= str_pad($left, $space) . ' ' . $right . "\n";
        }
        return $this;
    }

    /** Columns with fixed widths (negative width = right aligned). */
    public function cols(array $cells, array $widths): self
    {
        $line = '';
        foreach ($cells as $i => $c) {
            $w = $widths[$i];
            $c = self::encode((string) $c);
            $line .= $w < 0 ? str_pad(substr($c, 0, -$w), -$w, ' ', STR_PAD_LEFT) : str_pad(substr($c, 0, $w), $w);
        }
        $this->buf .= rtrim($line) . "\n";
        return $this;
    }

    public function hr(string $ch = '-'): self
    {
        $this->buf .= str_repeat($ch, $this->cols) . "\n";
        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->buf .= "\x1B\x64" . chr(max(0, min(255, $lines)));
        return $this;
    }

    public function cut(): self
    {
        $this->buf .= "\x1D\x56\x42\x03"; // feed 3 and partial cut
        return $this;
    }

    /** Kitchen buzzer (supported by most Epson-compatible kitchen printers). */
    public function beep(int $times = 2): self
    {
        $this->buf .= "\x1B\x42" . chr($times) . "\x03";
        return $this;
    }

    /** Opens the cash drawer on pin 2. */
    public function drawer(): self
    {
        $this->buf .= "\x1B\x70\x00\x19\xFA";
        return $this;
    }

    public function qr(string $data, int $size = 6): self
    {
        $len = strlen($data) + 3;
        $this->buf .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00"   // model 2
            . "\x1D\x28\x6B\x03\x00\x31\x43" . chr($size)     // module size
            . "\x1D\x28\x6B\x03\x00\x31\x45\x31"              // error correction M
            . "\x1D\x28\x6B" . chr($len % 256) . chr(intdiv($len, 256)) . "\x31\x50\x30" . $data
            . "\x1D\x28\x6B\x03\x00\x31\x51\x30";             // print
        return $this;
    }

    /** Monochrome raster image (GS v 0) from a PNG/JPG/WebP file, scaled to $maxWidth dots, dithered. */
    public function image(string $file, int $maxWidth = 384): self
    {
        if (!function_exists('imagecreatefromstring') || !is_file($file)) {
            return $this;
        }
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (!$src) {
            return $this;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $nw = min($maxWidth, $w);
        $nh = (int) round($h * $nw / $w);
        $img = imagecreatetruecolor($nw, $nh);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $bytesPerRow = (int) ceil($nw / 8);
        $data = '';
        $err = array_fill(0, $nw + 2, 0.0);
        for ($y = 0; $y < $nh; $y++) {
            $next = array_fill(0, $nw + 2, 0.0);
            $row = array_fill(0, $bytesPerRow, 0);
            for ($x = 0; $x < $nw; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $gray = $a > 100 ? 255 : 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
                $v = $gray + $err[$x + 1];
                $black = $v < 128;
                $e = $v - ($black ? 0 : 255);
                $err[$x + 2] += $e * 7 / 16;
                $next[$x] += $e * 3 / 16;
                $next[$x + 1] += $e * 5 / 16;
                $next[$x + 2] += $e / 16;
                if ($black) {
                    $row[intdiv($x, 8)] |= 0x80 >> ($x % 8);
                }
            }
            $err = $next;
            $data .= implode('', array_map('chr', $row));
        }
        $this->buf .= "\x1D\x76\x30\x00" . chr($bytesPerRow % 256) . chr(intdiv($bytesPerRow, 256)) . chr($nh % 256) . chr(intdiv($nh, 256)) . $data;
        return $this;
    }

    public function bytes(): string
    {
        return $this->buf;
    }
}
