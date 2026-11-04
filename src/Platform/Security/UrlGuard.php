<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Integrations\Exception\SsrfBlockedException;

/**
 * SSRF guard for every outbound request the platform makes on behalf of configuration or user input
 * (integrations, webhooks, SMS gateway, Web Push endpoints).
 *
 * Rules:
 *  - only http/https, a host is required, credentials in the URL are rejected;
 *  - the host is resolved (A + AAAA) and EVERY address must be public; private, loopback, link-local,
 *    CGNAT, multicast, reserved and documentation ranges are rejected unless $allowPrivate is true;
 *  - cloud metadata addresses (169.254.169.254, fd00:ec2::254, 100.100.100.200) are rejected ALWAYS,
 *    even with $allowPrivate (there is no legitimate integration that needs them);
 *  - the resolved addresses are returned so the caller can pin the connection to them
 *    (CURLOPT_RESOLVE) — this closes the DNS-rebinding window between check and connect.
 *
 * Redirects are not followed by {@see \App\Platform\Integrations\Http\HttpClient} (a redirect target
 * would bypass this check), so no redirect re-validation is needed.
 */
final class UrlGuard
{
    /** Always blocked (instance metadata services). */
    private const METADATA = ['169.254.169.254', 'fd00:ec2::254', '100.100.100.200', '169.254.170.2'];

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver host → IP list (tests inject a fake resolver)
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? \Closure::fromCallable($resolver) : static fn(string $host): array => self::dnsResolve($host);
    }

    /**
     * Validates the URL and returns the parsed target with the vetted IP addresses.
     *
     * @return array{scheme:string, host:string, port:int, ips:list<string>}
     * @throws SsrfBlockedException
     */
    public function assertAllowed(string $url, bool $allowPrivate = false): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException('Invalid URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfBlockedException("Scheme \"$scheme\" is not allowed (http/https only).");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfBlockedException('Credentials in the URL are not allowed.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            throw new SsrfBlockedException('URL host is empty.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new SsrfBlockedException("Host \"$host\" could not be resolved.", 'dns');
        }
        foreach ($ips as $ip) {
            $reason = self::blockReason($ip, $allowPrivate);
            if ($reason !== null) {
                throw new SsrfBlockedException("Destination $host ($ip) is not allowed: $reason.");
            }
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /** Syntax-only validation (no DNS) used when saving configuration. Returns an error or null. */
    public static function syntaxError(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'Invalid URL.';
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Only http:// and https:// URLs are allowed.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Credentials in the URL are not allowed.';
        }
        return null;
    }

    /** @return string|null reason when the IP is not an acceptable destination */
    public static function blockReason(string $ip, bool $allowPrivate = false): ?string
    {
        $ip = strtolower($ip);
        if (in_array($ip, self::METADATA, true)) {
            return 'cloud metadata endpoint';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // IPv4-mapped / translated addresses are evaluated as IPv4
            $bin = inet_pton($ip);
            if ($bin !== false && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                return self::blockReason((string) inet_ntop(substr($bin, 12)), $allowPrivate);
            }
            if ($allowPrivate) {
                return null;
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return "reserved/private range $cidr";
                }
            }
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return 'not an IP address';
        }
        if ($allowPrivate) {
            return null;
        }
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return "reserved/private range $cidr";
            }
        }
        return null;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr) + [1 => null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        try {
            return array_values(array_unique(($this->resolver)($host)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private static function dnsResolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (isset($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return $ips;
    }
}
