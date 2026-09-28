<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Outgoing e-mail (password links, online customers' verification codes and order status).
 * Drivers: log (writes .eml files to storage/mail — development), mail (PHP mail()), smtp (STARTTLS/SSL + AUTH LOGIN).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        $cfg = (array) App::config('mail', []);
        $from = (string) ($cfg['from'] ?? 'noreply@example.com');
        $fromName = (string) ($cfg['from_name'] ?? Settings::get('profile.name', 'Sofrexa'));
        $boundary = 'sfx' . bin2hex(random_bytes(8));
        $headers = [
            'From' => self::encode($fromName) . " <$from>",
            'To' => $to,
            'Subject' => self::encode($subject),
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'sofrexa') . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => $html !== null ? "multipart/alternative; boundary=\"$boundary\"" : 'text/plain; charset=UTF-8',
        ];
        if ($html !== null) {
            $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
                . "--$boundary--\r\n";
        } else {
            $headers['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($text));
        }

        $driver = (string) ($cfg['driver'] ?? 'log');
        try {
            $ok = match ($driver) {
                'mail' => mail($to, $headers['Subject'], $body, array_diff_key($headers, ['To' => 1, 'Subject' => 1]), '-f' . $from),
                'smtp' => self::smtp((array) ($cfg['smtp'] ?? []), $from, $to, $headers, $body),
                default => self::log($headers, $body),
            };
        } catch (\Throwable $e) {
            App::log('mail', 'send failed: ' . $e->getMessage(), ['to' => $to]);
            return false;
        }
        App::log('mail', ($ok ? 'sent' : 'failed') . " via $driver", ['to' => $to, 'subject' => $subject]);
        return $ok;
    }

    private static function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function raw(array $headers, string $body): string
    {
        $h = '';
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        return $h . "\r\n" . $body;
    }

    private static function log(array $headers, string $body): bool
    {
        $file = App::storage('mail') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        return (bool) file_put_contents($file, self::raw($headers, $body));
    }

    private static function smtp(array $c, string $from, string $to, array $headers, string $body): bool
    {
        $host = (string) ($c['host'] ?? '');
        $port = (int) ($c['port'] ?? 587);
        $secure = (string) ($c['secure'] ?? 'tls');
        if ($host === '') {
            throw new \RuntimeException('SMTP host is not configured');
        }
        $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, 15);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect: $err");
        }
        stream_set_timeout($fp, 15);
        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $cmd = static function (string $c, int $expect) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if ((int) substr($r, 0, 3) !== $expect) {
                throw new \RuntimeException('SMTP ' . strtok($c, ' ') . ': ' . trim($r));
            }
            return $r;
        };
        $read();
        $ehlo = 'EHLO ' . (gethostname() ?: 'sofrexa');
        $cmd($ehlo, 250);
        if ($secure === 'tls') {
            $cmd('STARTTLS', 220);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd($ehlo, 250);
        }
        if (!empty($c['user'])) {
            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode((string) $c['user']), 334);
            $cmd(base64_encode((string) ($c['pass'] ?? '')), 235);
        }
        $cmd("MAIL FROM:<$from>", 250);
        $cmd("RCPT TO:<$to>", 250);
        $cmd('DATA', 354);
        $cmd(str_replace("\r\n.", "\r\n..", self::raw($headers, $body)) . "\r\n.", 250);
        $cmd('QUIT', 221);
        fclose($fp);
        return true;
    }
}
