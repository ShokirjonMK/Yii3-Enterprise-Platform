<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Monitoring\HealthChecker;
use App\Platform\Redis\RedisInspector;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Predis\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Redis roles: the cache lives on its own evictable instance (REDIS_CACHE_HOST), sessions/locks on the "state"
 * instance with noeviction; cache entries without TTL expire; URL configuration (rediss/ACL) is parsed correctly.
 */
final class RedisRolesTest extends IntegrationTestCase
{
    public function testUrlConfiguration(): void
    {
        $base = ['mode' => 'standalone', 'host' => 'redis', 'port' => 6379, 'password' => null, 'database' => 0, 'tls' => false];
        $c = RedisClientFactory::fromUrl('rediss://app:p%40ss@redis.example.com:6380/3', $base);
        self::assertSame(['redis.example.com', 6380, 'app', 'p@ss', 3, true], [$c['host'], $c['port'], $c['username'], $c['password'], $c['database'], $c['tls']]);
        self::assertSame($base, RedisClientFactory::fromUrl('', $base));
        $this->expectException(\InvalidArgumentException::class);
        RedisClientFactory::fromUrl('http://x', $base);
    }

    public function testCacheIsSeparateFromStateAndExpires(): void
    {
        $inspector = $this->get(RedisInspector::class);
        if (!$inspector->cacheSeparate()) {
            self::markTestSkipped('REDIS_CACHE_HOST is not set — cache shares the state instance.');
        }
        $key = 'it-roles-' . bin2hex(random_bytes(4));
        $cache = $this->get(CacheInterface::class);
        self::assertTrue($cache->set($key, 'v'));
        self::assertSame('v', $cache->get($key));

        $prefix = $inspector->prefix() . 'cache:';
        $params = $this->get(\Yiisoft\Config\ConfigInterface::class)->get('params-console');
        $cacheRedis = RedisClientFactory::create(['role' => 'cache-test'] + $params['platform']['redisCache'], false);
        $ttl = (int) $cacheRedis->ttl($prefix . $key);
        self::assertGreaterThan(0, $ttl, 'entry written without TTL gets REDIS_CACHE_DEFAULT_TTL');
        self::assertLessThanOrEqual(86400, $ttl);

        // the key is on the cache instance, not on the state instance
        $state = $this->get(ClientInterface::class);
        self::assertSame(0, (int) $state->exists('cache:' . $key));
        self::assertSame(1, (int) $cacheRedis->exists($prefix . $key));
        $cache->delete($key);

        self::assertSame('noeviction', $inspector->info()['derived']['maxmemory_policy'] ?? null, 'state instance never evicts sessions');
        self::assertSame('allkeys-lfu', $inspector->instance('cache')->info()['derived']['maxmemory_policy'] ?? null);

        $health = $this->get(HealthChecker::class)->checkAll(['redis', 'redis_cache']);
        self::assertSame(HealthChecker::UP, $health['redis_cache']['status']);
        self::assertArrayNotHasKey('warning', $health['redis']['details']);
    }
}
