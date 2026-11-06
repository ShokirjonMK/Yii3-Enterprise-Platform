<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * PSR-16 decorator implementing the platform's Redis-outage policy for CACHE data:
 *  - any backend error is logged and treated as a cache miss / failed write (fail-open),
 *  - after an error the backend is bypassed for {@see $cooldownSeconds} to avoid adding latency to every call.
 *
 *  - entries written without a TTL get {@see $defaultTtl} (0 = forever), so the cache cannot grow without bound
 *    even when it shares a Redis instance that never evicts (noeviction).
 *
 * Only use for data that can be recomputed from PostgreSQL. Sessions, locks and rate limits have their own policies.
 */
final class FailSafeCache implements CacheInterface
{
    private static float $bypassUntil = 0.0;

    public function __construct(
        private readonly CacheInterface $inner,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $cooldownSeconds = 10,
        private readonly int $defaultTtl = 0,
    ) {}

    public static function isDegraded(): bool
    {
        return self::$bypassUntil > microtime(true);
    }

    public static function reset(): void
    {
        self::$bypassUntil = 0.0;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->guard(fn() => $this->inner->get($key, $default), $default);
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        return $this->guard(fn() => $this->inner->set($key, $value, $this->ttl($ttl)), false);
    }

    public function delete(string $key): bool
    {
        return $this->guard(fn() => $this->inner->delete($key), false);
    }

    public function clear(): bool
    {
        return $this->guard(fn() => $this->inner->clear(), false);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys, false);
        return $this->guard(
            fn() => $this->inner->getMultiple($keys, $default),
            array_fill_keys($keys, $default),
        );
    }

    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        return $this->guard(fn() => $this->inner->setMultiple($values, $this->ttl($ttl)), false);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->guard(fn() => $this->inner->deleteMultiple($keys), false);
    }

    public function has(string $key): bool
    {
        return $this->guard(fn() => $this->inner->has($key), false);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    private function guard(callable $operation, mixed $fallback): mixed
    {
        if (self::isDegraded()) {
            return $fallback;
        }
        try {
            return $operation();
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            self::$bypassUntil = microtime(true) + $this->cooldownSeconds;
            $this->logger?->warning('Cache backend unavailable; degraded to pass-through for {seconds}s', [
                'seconds' => $this->cooldownSeconds,
                'exception' => $e->getMessage(),
            ]);
            return $fallback;
        }
    }

    private function ttl(int|DateInterval|null $ttl): int|DateInterval|null
    {
        return $ttl === null && $this->defaultTtl > 0 ? $this->defaultTtl : $ttl;
    }
}
