<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the client IP honouring X-Forwarded-For only when the direct peer is a trusted proxy
 * (prevents IP spoofing that would bypass throttling and poison audit logs).
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies CIDR list */
    public function __construct(private readonly array $trustedProxies = []) {}

    public function resolve(ServerRequestInterface $request): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (!$this->isTrusted($remote)) {
            return $remote;
        }
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded === '') {
            return $remote;
        }
        // Walk from the right; the first untrusted address is the client
        $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!$this->isTrusted($hop)) {
                return $hop;
            }
            $remote = $hop;
        }
        return $remote;
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (strncmp($ipBin, $subBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return ($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask);
    }
}
