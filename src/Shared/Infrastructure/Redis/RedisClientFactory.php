<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Creates Predis clients for standalone, Sentinel (HA) or Cluster topologies.
 *
 * Two roles use separate configs (params platform.redis / platform.redisCache):
 *  - "state": sessions, locks, rate limits, OTP codes, config revision — must never be evicted (noeviction),
 *  - "cache": PSR-16 application cache — evictable (allkeys-lfu), may be a separate instance (REDIS_CACHE_*).
 *
 * Hardening:
 *  - REDIS_URL / REDIS_CACHE_URL (redis:// or rediss:// = TLS, user:password for ACL users, /db) or discrete vars,
 *  - TLS with peer verification (REDIS_TLS=true, REDIS_TLS_CA optional),
 *  - ACL user name (Redis 6+), Sentinel's own password, cluster auth,
 *  - persistent sockets in PHP-FPM with a per-role/per-database persistent id — avoids TCP + AUTH on every request
 *    and never shares a socket between clients that SELECT different databases,
 *  - separate connect and read/write timeouts, TCP_NODELAY.
 */
final class RedisClientFactory
{
    /**
     * @param array{mode:string,host:string,port:int,password:?string,database:int,sentinels:list<string>,sentinelService:string,prefix:string,timeout:float,
     *              username?:?string,tls?:bool,tlsCa?:?string,readTimeout?:float,persistent?:bool,sentinelPassword?:?string,role?:string} $config
     */
    public static function create(array $config, bool $withPrefix = true): ClientInterface
    {
        $tls = (bool) ($config['tls'] ?? false);
        $parameters = array_filter([
            'scheme' => $tls ? 'tls' : 'tcp',
            'username' => ($config['username'] ?? null) ?: null,
            'password' => ($config['password'] ?? null) ?: null,
            'database' => (int) $config['database'],
            'timeout' => (float) $config['timeout'],
            'read_write_timeout' => (float) ($config['readTimeout'] ?? 3.0),
            'tcp_nodelay' => true,
            'ssl' => $tls ? array_filter(['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => ($config['tlsCa'] ?? null) ?: null], static fn($v) => $v !== null) : null,
        ], static fn($v) => $v !== null);
        if (self::persistent($config)) {
            // persistent id = role + database: two clients of the same server on different databases never share a socket
            $parameters['persistent'] = 'yep-' . ($config['role'] ?? 'state') . '-' . $config['database'];
        }
        $options = $withPrefix ? ['prefix' => $config['prefix']] : [];

        return match ($config['mode']) {
            'sentinel' => new Client(
                array_map(static function (string $s) use ($config, $tls): array {
                    [$host, $port] = array_pad(explode(':', $s, 2), 2, '26379');
                    return array_filter(['scheme' => $tls ? 'tls' : 'tcp', 'host' => $host, 'port' => (int) $port, 'password' => ($config['sentinelPassword'] ?? null) ?: null, 'timeout' => (float) $config['timeout']], static fn($v) => $v !== null);
                }, $config['sentinels']),
                $options + [
                    'replication' => 'sentinel',
                    'service' => $config['sentinelService'],
                    'parameters' => $parameters,
                ],
            ),
            'cluster' => new Client(
                array_map(static function (string $s) use ($parameters): array {
                    [$host, $port] = array_pad(explode(':', $s, 2), 2, '6379');
                    return ['host' => $host, 'port' => (int) $port] + $parameters;
                }, $config['sentinels'] ?: [$config['host'] . ':' . $config['port']]),
                // Redis Cluster has no databases: always 0
                $options + ['cluster' => 'redis', 'parameters' => ['database' => 0] + $parameters],
            ),
            default => new Client(['host' => $config['host'], 'port' => (int) $config['port']] + $parameters, $options),
        };
    }

    /**
     * Builds a config array from REDIS_URL-style strings (redis://[user:pass@]host[:port][/db], rediss:// = TLS),
     * overriding the discrete values of $base. Unset parts keep the $base values.
     */
    public static function fromUrl(?string $url, array $base): array
    {
        if ($url === null || trim($url) === '') {
            return $base;
        }
        $p = parse_url(trim($url));
        if ($p === false || !in_array($p['scheme'] ?? '', ['redis', 'rediss'], true)) {
            throw new \InvalidArgumentException('Redis URL must start with redis:// or rediss://');
        }
        return array_merge($base, array_filter([
            'host' => $p['host'] ?? null,
            'port' => isset($p['port']) ? (int) $p['port'] : null,
            'username' => isset($p['user']) && rawurldecode($p['user']) !== '' ? rawurldecode($p['user']) : null,
            'password' => isset($p['pass']) ? rawurldecode($p['pass']) : null,
            'database' => isset($p['path']) && trim($p['path'], '/') !== '' ? (int) trim($p['path'], '/') : null,
        ], static fn($v) => $v !== null), ['tls' => ($p['scheme'] === 'rediss') || (bool) ($base['tls'] ?? false)]);
    }

    private static function persistent(array $config): bool
    {
        return PHP_SAPI === 'fpm-fcgi' && ($config['persistent'] ?? false) && $config['mode'] === 'standalone';
    }
}
