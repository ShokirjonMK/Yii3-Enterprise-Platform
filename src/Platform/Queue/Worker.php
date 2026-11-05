<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use App\Platform\Monitoring\Metrics;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

/**
 * Long-running queue worker.
 *  - registers itself in `workers` and heartbeats every 15 s,
 *  - graceful shutdown on SIGTERM/SIGINT (finishes the current job, then exits),
 *  - hard timeout per job via SIGALRM (pcntl) — the job is failed and retried,
 *  - recycles itself after N jobs or memory limit (protection against leaks),
 *  - cooperative cancellation via JobContext::isCancelled().
 * Concurrency = number of worker processes (scale the `worker` Deployment / compose service).
 */
final class Worker
{
    private bool $stop = false;
    private float $lastHeartbeat = 0;
    private int $processed = 0;
    private int $failed = 0;
    private ?string $currentJob = null;
    private string $id;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly HandlerRegistry $handlers,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $context,
        private readonly Metrics $metrics,
    ) {
        $this->id = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @param list<string> $queues
     * @return int jobs handled by this run (the worker object may be reused, e.g. in tests: counters are per run)
     */
    public function run(array $queues, float $sleep = 1.0, int $maxJobs = 1000, int $memoryLimitMb = 256, bool $once = false): int
    {
        $this->installSignals();
        $this->register($queues);
        $this->logger->info('Worker {id} started for queues {queues}', ['id' => $this->id, 'queues' => implode(',', $queues)]);
        $before = $this->processed + $this->failed;

        try {
            while (!$this->stop) {
                $this->heartbeat();
                $job = $this->queue->reserve($queues, $this->id);
                if ($job === null) {
                    if ($once) {
                        break;
                    }
                    $this->sleep($sleep);
                    continue;
                }
                $this->execute($job);
                if ($once || $this->processed + $this->failed - $before >= $maxJobs || memory_get_usage(true) > $memoryLimitMb * 1024 * 1024) {
                    break;
                }
            }
        } finally {
            $this->db->createCommand()->update('workers', ['status' => 'stopped', 'current_job_id' => null, 'heartbeat_at' => new Expression('now()')], ['id' => $this->id])->execute();
            $this->logger->info('Worker {id} stopped after {n} jobs', ['id' => $this->id, 'n' => $this->processed + $this->failed - $before]);
        }
        return $this->processed + $this->failed - $before;
    }

    public function execute(array $job): void
    {
        $this->currentJob = (string) $job['id'];
        $this->context->reset();
        $this->context->setChannel('worker');
        $this->context->setCorrelationId($job['correlation_id'] ?? null);
        $this->context->setTenantId($job['tenant_id'] ?? null);
        $this->db->createCommand()->update('workers', ['status' => 'busy', 'current_job_id' => $job['id']], ['id' => $this->id])->execute();

        $start = hrtime(true);
        $timeout = (int) $job['timeout_seconds'];
        $ctx = new JobContext(
            (string) $job['id'],
            (string) $job['type'],
            (int) $job['attempts'],
            $job['correlation_id'] ?? null,
            fn(): bool => $this->queue->isCancelRequested((string) $job['id']),
            fn(int $p) => $this->queue->progress((string) $job['id'], $p),
        );

        try {
            if (function_exists('pcntl_alarm')) {
                pcntl_signal(SIGALRM, static function () use ($timeout): void {
                    throw new JobTimeout("Job exceeded its timeout of {$timeout}s.");
                });
                pcntl_alarm($timeout);
            }
            $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
            $this->handlers->get((string) $job['type'])->handle(is_array($payload) ? $payload : [], $ctx);
            $this->clearAlarm();
            $ms = (int) ((hrtime(true) - $start) / 1e6);
            $this->queue->complete((string) $job['id'], $ms, $this->id);
            $this->processed++;
            $this->metrics->inc('jobs_processed_total', 'Processed jobs', ['queue' => $job['queue'], 'type' => $job['type'], 'status' => 'succeeded']);
            $this->metrics->observe('job_duration_seconds', 'Job duration', $ms / 1000, ['queue' => $job['queue']], [0.05, 0.1, 0.5, 1, 5, 15, 60, 300, 900]);
        } catch (Throwable $e) {
            $this->clearAlarm();
            $ms = (int) ((hrtime(true) - $start) / 1e6);
            if ($this->db->getTransaction() !== null) {
                $this->db->getTransaction()->rollBack();
            }
            $status = $this->queue->fail($job, $e, $ms, $this->id, $e instanceof PermanentJobFailure || $e instanceof \JsonException);
            $this->failed++;
            $this->metrics->inc('jobs_processed_total', 'Processed jobs', ['queue' => $job['queue'], 'type' => $job['type'], 'status' => $status]);
            $this->logger->warning('Job {type} #{id} failed (attempt {a}): {error} → {status}', [
                'type' => $job['type'], 'id' => $job['id'], 'a' => $job['attempts'], 'error' => $e->getMessage(), 'status' => $status,
            ]);
        } finally {
            $this->currentJob = null;
            $this->db->createCommand()->update('workers', [
                'status' => 'idle', 'current_job_id' => null, 'jobs_processed' => $this->processed, 'jobs_failed' => $this->failed,
                'memory_bytes' => memory_get_usage(true), 'heartbeat_at' => new Expression('now()'),
            ], ['id' => $this->id])->execute();
        }
    }

    public function requestStop(): void
    {
        $this->stop = true;
    }

    private function register(array $queues): void
    {
        $this->db->createCommand()->upsert('workers', [
            'id' => $this->id, 'type' => 'queue', 'hostname' => (string) gethostname(), 'pid' => getmypid(),
            'queues' => implode(',', $queues), 'status' => 'idle', 'started_at' => new Expression('now()'), 'heartbeat_at' => new Expression('now()'),
        ])->execute();
        $this->lastHeartbeat = microtime(true);
    }

    private function heartbeat(): void
    {
        if (microtime(true) - $this->lastHeartbeat < 15) {
            return;
        }
        $this->db->createCommand()->update('workers', ['heartbeat_at' => new Expression('now()'), 'memory_bytes' => memory_get_usage(true)], ['id' => $this->id])->execute();
        $this->lastHeartbeat = microtime(true);
    }

    private function installSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (int $signo): void {
            $this->logger->notice('Worker {id} received signal {s}; finishing current job', ['id' => $this->id, 's' => $signo]);
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function clearAlarm(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    private function sleep(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (!$this->stop && microtime(true) < $until) {
            usleep(100_000);
        }
    }
}
