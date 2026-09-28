<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Network addresses: CIDR ranges (IPv4 and IPv6) and the proxies whose client-address header is believed. */
final class Net
{
    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Whether $ip is inside $cidr ("192.168.1.0/24", "2606:4700::/32") or equal to a single address. */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $a = @inet_pton($ip);
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    public static function inAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $c) {
            if (self::inCidr($ip, (string) $c)) {
                return true;
            }
        }
        return false;
    }

    /** Config trusted_proxies (default: Cloudflare): the only senders whose CF-Connecting-IP is believed. */
    public static function trustedProxies(): array
    {
        $out = [];
        foreach ((array) App::config('trusted_proxies', ['cloudflare']) as $p) {
            array_push($out, ...($p === 'cloudflare' ? self::CLOUDFLARE : [(string) $p]));
        }
        return $out;
    }

    /**
     * The client's address: the connection's own, or the one Cloudflare passes on — only when the connection really comes
     * from Cloudflare (anyone reaching the server directly could write that header and pass for the restaurant network).
     */
    public static function clientIp(): string
    {
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $fwd = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($fwd !== '' && filter_var($fwd, FILTER_VALIDATE_IP) && self::inAny($peer, self::trustedProxies())) {
            return $fwd;
        }
        return $peer;
    }
}
