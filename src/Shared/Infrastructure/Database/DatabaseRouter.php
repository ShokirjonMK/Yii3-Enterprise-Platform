<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Read/write splitting.
 *
 *  - {@see primary()} is used for every write and for reads that must see their own writes.
 *  - {@see read()} returns a healthy replica, or the primary when:
 *      * no replicas are configured,
 *      * a transaction is active on the primary,
 *      * a write happened recently in this process (stickiness window),
 *      * all replicas failed their connection attempt (they are skipped for 30 s).
 *
 * Replica connections are created lazily, so configuring replicas costs nothing until a read happens.
 */
final class DatabaseRouter
{
    /** @var array<int, ConnectionInterface|null> */
    private array $replicas = [];
    /** @var array<int, float> replica index => unhealthy-until timestamp */
    private static array $unhealthyUntil = [];
    private float $lastWriteAt = 0.0;

    /**
     * @param list<Closure(): ConnectionInterface> $replicaFactories
     */
    public function __construct(
        private readonly ConnectionInterface $primary,
        private readonly array $replicaFactories = [],
        private readonly int $stickinessSeconds = 5,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function primary(): ConnectionInterface
    {
        return $this->primary;
    }

    public function read(): ConnectionInterface
    {
        if ($this->replicaFactories === []
            || $this->primary->getTransaction() !== null
            || (microtime(true) - $this->lastWriteAt) < $this->stickinessSeconds
        ) {
            return $this->primary;
        }

        $indexes = array_keys($this->replicaFactories);
        shuffle($indexes);
        foreach ($indexes as $i) {
            if ((self::$unhealthyUntil[$i] ?? 0) > microtime(true)) {
                continue;
            }
            try {
                $connection = $this->replicas[$i] ??= ($this->replicaFactories[$i])();
                $connection->open();
                return $connection;
            } catch (Throwable $e) {
                self::$unhealthyUntil[$i] = microtime(true) + 30;
                $this->replicas[$i] = null;
                $this->logger?->warning('Read replica #{index} unavailable, falling back', ['index' => $i, 'exception' => $e->getMessage()]);
            }
        }

        return $this->primary;
    }

    public function markWrite(): void
    {
        $this->lastWriteAt = microtime(true);
    }

    public function replicaCount(): int
    {
        return count($this->replicaFactories);
    }

    /**
     * @return list<array{index:int, healthy:bool, error:?string, lagSeconds:?float}>
     */
    public function replicaStatus(): array
    {
        $result = [];
        foreach (array_keys($this->replicaFactories) as $i) {
            try {
                $connection = $this->replicas[$i] ??= ($this->replicaFactories[$i])();
                $lag = $connection->createCommand(
                    'SELECT CASE WHEN pg_is_in_recovery() THEN EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp()) ELSE 0 END',
                )->queryScalar();
                $result[] = ['index' => $i, 'healthy' => true, 'error' => null, 'lagSeconds' => $lag === null ? null : (float) $lag];
            } catch (Throwable $e) {
                $result[] = ['index' => $i, 'healthy' => false, 'error' => $e->getMessage(), 'lagSeconds' => null];
            }
        }
        return $result;
    }

    /**
     * Executes $callback in a primary transaction and marks the router as written-to.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $result = $this->primary->transaction($callback);
        $this->markWrite();
        return $result;
    }
}
