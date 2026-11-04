<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimit;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Atomic fixed-window rate limiter shared by all application instances (Lua INCR + PEXPIRE).
 *
 * Why custom: yiisoft/rate-limiter 3.0 stores counters through PSR-16 get/set which is not atomic
 * across instances. This implementation is a single round-trip and race-free.
 *
 * Outage policy: when $failOpen is true (default; general API traffic) an unavailable Redis allows the
 * request and logs a warning. Security-sensitive limits (login) are additionally enforced in PostgreSQL.
 */
final class RedisRateLimiter
{
    private const SCRIPT = <<<'LUA'
        local current = redis.call("INCR", KEYS[1])
        if current == 1 then redis.call("PEXPIRE", KEYS[1], ARGV[1]) end
        local ttl = redis.call("PTTL", KEYS[1])
        return {current, ttl}
        LUA;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function hit(string $key, int $limit, int $windowSeconds, bool $failOpen = true): RateLimitResult
    {
        try {
            /** @var array{0:int,1:int} $r */
            $r = $this->redis->eval(self::SCRIPT, 1, 'rl:' . $key, (string) ($windowSeconds * 1000));
            $count = (int) $r[0];
            $resetIn = max(0, (int) ceil(((int) $r[1]) / 1000));
            return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), $resetIn);
        } catch (Throwable $e) {
            $this->logger?->warning('Rate limiter backend unavailable', ['exception' => $e->getMessage(), 'key' => $key]);
            return new RateLimitResult($failOpen, $limit, $failOpen ? $limit : 0, $windowSeconds);
        }
    }

    public function reset(string $key): void
    {
        try {
            $this->redis->del(['rl:' . $key]);
        } catch (Throwable) {
        }
    }
}
