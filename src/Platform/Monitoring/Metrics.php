<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Prometheus instrumentation (promphp/prometheus_client_php). Counters/histograms are aggregated in
 * Redis so that all PHP-FPM workers and pods expose one consistent /metrics view per instance group.
 * Metric failures never break the request path.
 */
final class Metrics
{
    private const NS = 'platform';
    private ?CollectorRegistry $registry = null;
    private bool $broken = false;

    public function __construct(
        private readonly array $redisConfig,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Adapter $adapter = null,
    ) {}

    public function registry(): CollectorRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }
        $adapter = $this->adapter;
        if ($adapter === null) {
            try {
                Redis::setPrefix($this->redisConfig['prefix'] . 'prom:');
                $adapter = new Redis([
                    'host' => $this->redisConfig['host'],
                    'port' => $this->redisConfig['port'],
                    'password' => $this->redisConfig['password'],
                    'database' => $this->redisConfig['database'],
                    'timeout' => 0.5,
                    'read_timeout' => '1',
                    'persistent_connections' => true,
                ]);
            } catch (Throwable $e) {
                $adapter = new InMemory();
            }
        }
        return $this->registry = new CollectorRegistry($adapter, false);
    }

    public function inc(string $name, string $help, array $labels = [], float $by = 1): void
    {
        $this->safe(function () use ($name, $help, $labels, $by): void {
            $this->registry()->getOrRegisterCounter(self::NS, $name, $help, array_keys($labels))->incBy($by, array_values($labels));
        });
    }

    public function observe(string $name, string $help, float $value, array $labels = [], ?array $buckets = null): void
    {
        $this->safe(function () use ($name, $help, $value, $labels, $buckets): void {
            $this->registry()->getOrRegisterHistogram(self::NS, $name, $help, array_keys($labels), $buckets ?? [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10])
                ->observe($value, array_values($labels));
        });
    }

    public function gauge(string $name, string $help, float $value, array $labels = []): void
    {
        $this->safe(function () use ($name, $help, $value, $labels): void {
            $this->registry()->getOrRegisterGauge(self::NS, $name, $help, array_keys($labels))->set($value, array_values($labels));
        });
    }

    private function safe(callable $fn): void
    {
        if ($this->broken) {
            return;
        }
        try {
            $fn();
        } catch (Throwable $e) {
            $this->broken = true;
            $this->logger?->warning('Metrics backend unavailable', ['exception' => $e->getMessage()]);
        }
    }
}
