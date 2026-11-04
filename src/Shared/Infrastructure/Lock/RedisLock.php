<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Predis\ClientInterface;

/**
 * Single-instance Redis lock (SET NX PX + token-checked release via Lua).
 * Suitable for efficiency locks (avoid duplicate work). For correctness-critical mutual exclusion
 * use {@see PostgresAdvisoryLock} or a fencing token stored in PostgreSQL.
 */
final class RedisLock implements LockInterface
{
    private const RELEASE = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end
        LUA;

    private const REFRESH = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("pexpire", KEYS[1], ARGV[2]) else return 0 end
        LUA;

    public function __construct(private readonly ClientInterface $redis) {}

    public function acquire(string $name, int $ttlSeconds): ?string
    {
        $token = bin2hex(random_bytes(16));
        $ok = $this->redis->set('lock:' . $name, $token, 'PX', $ttlSeconds * 1000, 'NX');
        return $ok !== null && (string) $ok === 'OK' ? $token : null;
    }

    public function release(string $name, string $token): bool
    {
        return (int) $this->redis->eval(self::RELEASE, 1, 'lock:' . $name, $token) === 1;
    }

    public function refresh(string $name, string $token, int $ttlSeconds): bool
    {
        return (int) $this->redis->eval(self::REFRESH, 1, 'lock:' . $name, $token, (string) ($ttlSeconds * 1000)) === 1;
    }
}
