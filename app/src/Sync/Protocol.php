<?php
declare(strict_types=1);

namespace Sofrexa\Sync;

use Sofrexa\Core\{App, Clock};

/**
 * Transport between the till PC and the web copy: JSON over HTTPS, signed with HMAC-SHA256 of
 * "timestamp.body" using the shared sync.key. Requests older than five minutes are refused.
 * Backups and snapshots travel encrypted (libsodium secretstream, key derived from sync.key).
 */
final class Protocol
{
    public const MAX_SKEW_MS = 300_000;
    public const CHUNK = 4_194_304; // 4 MB per upload request (fits common hosting limits)

    public static function key(): string
    {
        $k = (string) App::config('sync.key', '');
        if (strlen($k) < 24) {
            throw new \RuntimeException('sync.key is missing or too short (min 24 characters)');
        }
        return $k;
    }

    public static function sign(string $ts, string $body): string
    {
        return hash_hmac('sha256', $ts . '.' . $body, self::key());
    }

    public static function verify(string $ts, string $sig, string $body): bool
    {
        if (!ctype_digit($ts) || abs(Clock::ms() - (int) $ts) > self::MAX_SKEW_MS) {
            return false;
        }
        return hash_equals(self::sign($ts, $body), $sig);
    }

    /** POST to the web copy. Returns the decoded JSON reply. */
    public static function post(string $path, array|string $payload, string $contentType = 'application/json', array $query = []): array
    {
        ksort($query);
        $url = rtrim((string) App::config('sync.remote_url'), '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $body = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $payload;
        $ts = (string) Clock::ms();
        $sigBody = $contentType === 'application/json' ? $body : hash('sha256', $body) . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $contentType,
                'Accept: application/json',
                'X-Sofrexa-Ts: ' . $ts,
                'X-Sofrexa-Sig: ' . self::sign($ts, $sigBody),
                'X-Sofrexa-Device: ' . rawurlencode((string) App::config('device_name')),
            ],
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($res === false) {
            throw new \RuntimeException('connection: ' . $err);
        }
        $data = json_decode((string) $res, true);
        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            throw new \RuntimeException('HTTP ' . $code . ': ' . (is_array($data) ? ($data['error'] ?? 'error') : substr((string) $res, 0, 200)));
        }
        return $data;
    }

    /** Checks the signature of an incoming request. For binary uploads the body hash + query is signed. */
    public static function checkRequest(string $body, bool $binary = false): void
    {
        $ts = (string) ($_SERVER['HTTP_X_SOFREXA_TS'] ?? '');
        $sig = (string) ($_SERVER['HTTP_X_SOFREXA_SIG'] ?? '');
        $query = $_GET;
        ksort($query);
        $signed = $binary ? hash('sha256', $body) . http_build_query($query) : $body;
        if (!App::isWeb() || !self::verify($ts, $sig, $signed)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'signature']);
            exit;
        }
    }

    // ------------------------------------------------------------ encryption of files

    private static function fileKey(): string
    {
        return sodium_crypto_generichash('sofrexa-file-v1|' . self::key(), '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    public static function encryptFile(string $in, string $out): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::fileKey());
        $r = fopen($in, 'rb');
        $w = fopen($out, 'wb');
        fwrite($w, $header);
        do {
            $chunk = (string) fread($r, 65536);
            $tag = feof($r) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($w, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fclose($r);
        fclose($w);
    }

    public static function decryptFile(string $in, string $out): void
    {
        $r = fopen($in, 'rb');
        $w = fopen($out, 'wb');
        $header = (string) fread($r, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, self::fileKey());
        $ok = false;
        while (!feof($r)) {
            $c = (string) fread($r, 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($c === '') {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
            if ($res === false) {
                break;
            }
            [$plain, $tag] = $res;
            fwrite($w, $plain);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $ok = true;
                break;
            }
        }
        fclose($r);
        fclose($w);
        if (!$ok) {
            @unlink($out);
            throw new \RuntimeException('decryption failed');
        }
    }
}
