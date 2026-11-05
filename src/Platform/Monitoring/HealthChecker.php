<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Storage\ObjectStorage;
use Predis\ClientInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Dependency health checks used by /health/ready, the admin dashboard, the installer and alerts.
 * Every check is time-boxed and never throws.
 */
final class HealthChecker
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const DEGRADED = 'degraded';
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClientInterface $redis,
        private readonly KafkaConfig $kafka,
        private readonly ObjectStorage $storage,
        private readonly array $mailer,
        private readonly ?\App\Platform\Redis\RedisInspector $redisInspector = null,
    ) {}

    /**
     * @param list<string>|null $only
     * @return array<string, array{status:string, latency_ms:float, details:array}>
     */
    public function checkAll(?array $only = null): array
    {
        $checks = [
            'postgres' => fn() => $this->postgres(),
            'redis' => fn() => $this->redis(),
            'redis_cache' => fn() => $this->redisCache(),
            'kafka' => fn() => $this->kafkaCheck(),
            'storage' => fn() => $this->storage->health(),
            'mailer' => fn() => $this->mailerCheck(),
            'workers' => fn() => $this->workers(),
            'disk' => fn() => $this->disk(),
        ];
        $result = [];
        foreach ($checks as $name => $check) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            $t = microtime(true);
            try {
                [$status, $details] = $check();
            } catch (Throwable $e) {
                [$status, $details] = [self::DOWN, ['error' => $e->getMessage()]];
            }
            $result[$name] = ['status' => $status, 'latency_ms' => round((microtime(true) - $t) * 1000, 1), 'details' => $details];
        }
        return $result;
    }

    public static function overall(array $results, array $critical = ['postgres', 'redis']): string
    {
        $overall = self::UP;
        foreach ($results as $name => $r) {
            if ($r['status'] === self::DOWN && in_array($name, $critical, true)) {
                return self::DOWN;
            }
            if ($r['status'] === self::DOWN || $r['status'] === self::DEGRADED) {
                $overall = self::DEGRADED;
            }
        }
        return $overall;
    }

    private function postgres(): array
    {
        $row = $this->db->createCommand("SELECT version() AS v, pg_is_in_recovery() AS replica, current_setting('max_connections')::int AS max_conn,
            (SELECT count(*) FROM pg_stat_activity) AS connections, pg_database_size(current_database()) AS size")->queryOne();
        $pending = null;
        return [self::UP, [
            'version' => preg_replace('/ on .*/', '', (string) $row['v']),
            'role' => ($row['replica'] === true || $row['replica'] === 't') ? 'replica' : 'primary',
            'connections' => (int) $row['connections'] . '/' . (int) $row['max_conn'],
            'size_bytes' => (int) $row['size'],
        ]];
    }

    private function redis(): array
    {
        $pong = (string) $this->redis->ping();
        $info = $this->redis->info('memory');
        $mem = $info['Memory'] ?? $info;
        $policy = $mem['maxmemory_policy'] ?? null;
        return [str_contains(strtoupper($pong), 'PONG') ? self::UP : self::DOWN, array_filter([
            'used_memory' => $mem['used_memory_human'] ?? null,
            'maxmemory' => $mem['maxmemory_human'] ?? null,
            'maxmemory_policy' => $policy,
            // sessions/locks/OTP live here: an evicting policy signs users out when memory is full
            'warning' => $policy !== null && $policy !== 'noeviction' ? 'maxmemory-policy evicts sessions and locks; use noeviction and a separate cache instance' : null,
        ], static fn($v) => $v !== null)];
    }

    /** Separate cache instance (REDIS_CACHE_HOST). Not critical: the cache is fail-open. */
    private function redisCache(): array
    {
        if ($this->redisInspector === null || !$this->redisInspector->cacheSeparate()) {
            return [self::NOT_CONFIGURED, ['reason' => 'cache shares the state instance (REDIS_CACHE_HOST empty)']];
        }
        $ping = $this->redisInspector->instance('cache')->ping();
        return [$ping['ok'] ? self::UP : self::DOWN, $ping['ok'] ? ['latency_ms' => $ping['latency_ms']] : ['error' => $ping['error'], 'impact' => 'cache bypassed (fail-open), application keeps working']];
    }

    private function kafkaCheck(): array
    {
        if (!$this->kafka->enabled) {
            return [self::NOT_CONFIGURED, ['reason' => 'KAFKA_ENABLED=false']];
        }
        if (!extension_loaded('rdkafka')) {
            return [self::DOWN, ['error' => 'ext-rdkafka not installed']];
        }
        $producer = new \RdKafka\Producer($this->kafka->producerConf());
        $meta = $producer->getMetadata(true, null, 3000);
        $brokers = [];
        foreach ($meta->getBrokers() as $b) {
            $brokers[] = $b->getHost() . ':' . $b->getPort();
        }
        return [$brokers === [] ? self::DOWN : self::UP, ['brokers' => $brokers, 'topics' => count(iterator_to_array($meta->getTopics()))]];
    }

    private function mailerCheck(): array
    {
        $dsn = (string) ($this->mailer['dsn'] ?? '');
        if ($dsn === '' || str_starts_with($dsn, 'null://')) {
            return [self::NOT_CONFIGURED, []];
        }
        $parts = parse_url($dsn);
        if (!isset($parts['host'])) {
            return [self::NOT_CONFIGURED, ['reason' => 'unparseable DSN']];
        }
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'smtps' ? 465 : 25);
        $fp = @fsockopen($parts['host'], (int) $port, $errno, $errstr, 2.0);
        if ($fp === false) {
            return [self::DOWN, ['host' => $parts['host'] . ':' . $port, 'error' => $errstr]];
        }
        $banner = fgets($fp, 512);
        fclose($fp);
        return [str_starts_with((string) $banner, '220') ? self::UP : self::DEGRADED, ['host' => $parts['host'] . ':' . $port]];
    }

    private function workers(): array
    {
        $rows = $this->db->createCommand("SELECT type, count(*) FILTER (WHERE heartbeat_at > now() - interval '90 seconds' AND status <> 'stopped') AS alive
            FROM workers GROUP BY type")->queryAll();
        $alive = [];
        foreach ($rows as $r) {
            $alive[$r['type']] = (int) $r['alive'];
        }
        $queueAlive = $alive['queue'] ?? 0;
        return [$queueAlive > 0 ? self::UP : self::DEGRADED, ['alive' => $alive]];
    }

    private function disk(): array
    {
        $free = @disk_free_space('/app/runtime');
        $total = @disk_total_space('/app/runtime');
        if ($free === false || $total === false || $total <= 0) {
            return [self::NOT_CONFIGURED, []];
        }
        $pct = round(100 - ($free / $total * 100), 1);
        return [$pct > 90 ? self::DEGRADED : self::UP, ['used_percent' => $pct, 'free_bytes' => (int) $free]];
    }
}
