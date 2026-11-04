<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Context\RequestContext;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Durable job queue on PostgreSQL (`FOR UPDATE SKIP LOCKED`).
 *
 * Why PostgreSQL and not yiisoft/queue: yiisoft/queue has no stable release yet (dev-master only);
 * a database queue also gives transactional enqueue — a job pushed inside a business transaction
 * exists if and only if the transaction commits. Throughput (~thousands jobs/s per node) is ample for
 * platform workloads; high-volume streams go through Kafka.
 */
final class JobQueue
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RequestContext $context,
        private readonly AuditLogger $audit,
        private readonly HandlerRegistry $handlers,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @param array{queue?:string, delay?:int, priority?:int, max_attempts?:int, timeout?:int, backoff?:int, idempotency_key?:string, tenant_id?:string} $options
     * @return string job id (existing id when the idempotency key was already used)
     */
    public function push(string $type, array $payload = [], array $options = []): string
    {
        if (!$this->handlers->has($type)) {
            throw new \InvalidArgumentException("No handler registered for job type \"$type\".");
        }
        $id = Uuid::v7();
        $row = [
            'id' => $id,
            'queue' => $options['queue'] ?? $this->handlers->defaultQueue($type),
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'priority' => $options['priority'] ?? 100,
            'max_attempts' => $options['max_attempts'] ?? 5,
            'timeout_seconds' => $options['timeout'] ?? 300,
            'backoff_seconds' => $options['backoff'] ?? 10,
            'available_at' => (new DateTimeImmutable('+' . (int) ($options['delay'] ?? 0) . ' seconds'))->format('Y-m-d H:i:s.uP'),
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'correlation_id' => $this->context->correlationId(),
            'tenant_id' => $options['tenant_id'] ?? $this->context->tenantId(),
            'created_by' => $this->context->userId(),
        ];
        if ($row['idempotency_key'] !== null) {
            $sql = 'INSERT INTO jobs (id, queue, type, payload, priority, max_attempts, timeout_seconds, backoff_seconds, available_at, idempotency_key, correlation_id, tenant_id, created_by)
                    VALUES (:id, :queue, :type, CAST(:payload AS jsonb), :priority, :max_attempts, :timeout_seconds, :backoff_seconds, :available_at, :idempotency_key, :correlation_id, :tenant_id, :created_by)
                    ON CONFLICT (idempotency_key) WHERE idempotency_key IS NOT NULL DO NOTHING RETURNING id';
            $params = [];
            foreach ($row as $k => $v) {
                $params[':' . $k] = $v;
            }
            $inserted = $this->db->createCommand($sql, $params)->queryScalar();
            if ($inserted === false || $inserted === null) {
                return (string) (new Query($this->db))->select('id')->from('jobs')->where(['idempotency_key' => $row['idempotency_key']])->scalar();
            }
            return (string) $inserted;
        }
        $this->db->createCommand()->insert('jobs', $row)->execute();
        return $id;
    }

    /**
     * Atomically reserves the next runnable job from the given queues (paused queues are skipped).
     *
     * @param list<string> $queues
     * @return array<string,mixed>|null
     */
    public function reserve(array $queues, string $workerId): ?array
    {
        $placeholders = [];
        $params = [':worker' => $workerId];
        foreach (array_values($queues) as $i => $q) {
            $placeholders[] = ':q' . $i;
            $params[':q' . $i] = $q;
        }
        $in = implode(',', $placeholders);
        $row = $this->db->createCommand(
            "UPDATE jobs SET status = 'running', attempts = attempts + 1, reserved_by = :worker,
                    started_at = now(), reserved_until = now() + make_interval(secs => timeout_seconds + 30)
             WHERE id = (
                 SELECT j.id FROM jobs j
                 WHERE j.status = 'pending' AND j.available_at <= now() AND j.queue IN ($in)
                   AND NOT EXISTS (SELECT 1 FROM queue_state s WHERE s.queue = j.queue AND s.paused)
                 ORDER BY j.priority, j.available_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING *",
            $params,
        )->queryOne();
        return $row ?: null;
    }

    public function complete(string $id, int $durationMs, string $workerId): void
    {
        $this->db->transaction(function () use ($id, $durationMs, $workerId): void {
            $this->db->createCommand()->update('jobs', [
                'status' => 'succeeded', 'finished_at' => new Expression('now()'), 'progress' => 100,
                'reserved_by' => null, 'reserved_until' => null, 'last_error' => null,
            ], ['id' => $id])->execute();
            $this->attempt($id, $workerId, 'succeeded', null, $durationMs);
        });
    }

    /**
     * Records a failure and schedules a retry with exponential backoff + jitter, or marks the job dead.
     */
    public function fail(array $job, \Throwable $error, int $durationMs, string $workerId, bool $permanent = false): string
    {
        $attempts = (int) $job['attempts'];
        $max = (int) $job['max_attempts'];
        $message = mb_substr($error::class . ': ' . \App\Shared\Infrastructure\Logging\Redactor::redactString($error->getMessage()), 0, 4000);
        $cancelled = $error instanceof JobCancelled;
        $dead = $permanent || $cancelled || $attempts >= $max;
        $status = $cancelled ? 'cancelled' : ($dead ? 'dead' : 'pending');
        $delay = (int) min(3600, ((int) $job['backoff_seconds']) * (2 ** max(0, $attempts - 1))) + random_int(0, 5);

        $this->db->transaction(function () use ($job, $status, $delay, $message, $durationMs, $workerId, $dead): void {
            $this->db->createCommand()->update('jobs', [
                'status' => $status,
                'available_at' => $dead ? new Expression('available_at') : (new DateTimeImmutable("+$delay seconds"))->format('Y-m-d H:i:s.uP'),
                'finished_at' => $dead ? new Expression('now()') : null,
                'last_error' => $message,
                'reserved_by' => null,
                'reserved_until' => null,
            ], ['id' => $job['id']])->execute();
            $this->attempt((string) $job['id'], $workerId, $dead ? $status : 'failed', $message, $durationMs);
        });
        return $status;
    }

    public function progress(string $id, int $percent): void
    {
        $this->db->createCommand()->update('jobs', ['progress' => $percent], ['id' => $id])->execute();
    }

    public function isCancelRequested(string $id): bool
    {
        return (bool) (new Query($this->db))->select('cancel_requested')->from('jobs')->where(['id' => $id])->scalar();
    }

    // ------------------------------------------------------------------ operator actions

    public function retry(string $id, ?string $actorId = null): void
    {
        $job = $this->find($id);
        if (!in_array($job['status'], ['failed', 'dead', 'cancelled'], true)) {
            throw new ConflictException('Only failed, dead or cancelled jobs can be retried.', 'invalid_state');
        }
        $this->db->createCommand()->update('jobs', [
            'status' => 'pending', 'available_at' => new Expression('now()'), 'attempts' => 0,
            'cancel_requested' => false, 'finished_at' => null,
        ], ['id' => $id])->execute();
        $this->audit->record('job.retried', 'job', $id, ['status' => $job['status']], ['status' => 'pending'], ['type' => $job['type']], category: 'operations', actorId: $actorId);
    }

    public function cancel(string $id, ?string $actorId = null): void
    {
        $job = $this->find($id);
        if ($job['status'] === 'pending') {
            $this->db->createCommand()->update('jobs', ['status' => 'cancelled', 'finished_at' => new Expression('now()'), 'cancel_requested' => true], ['id' => $id, 'status' => 'pending'])->execute();
        } elseif ($job['status'] === 'running') {
            $this->db->createCommand()->update('jobs', ['cancel_requested' => true], ['id' => $id])->execute();
        } else {
            throw new ConflictException('Only pending or running jobs can be cancelled.', 'invalid_state');
        }
        $this->audit->record('job.cancelled', 'job', $id, ['status' => $job['status']], null, ['type' => $job['type']], category: 'operations', actorId: $actorId);
    }

    public function pauseQueue(string $queue, bool $paused, ?string $actorId = null): void
    {
        $this->db->createCommand()->upsert('queue_state', [
            'queue' => $queue, 'paused' => $paused, 'paused_by' => $paused ? $actorId : null, 'paused_at' => $paused ? new Expression('now()') : null,
        ])->execute();
        $this->audit->record($paused ? 'queue.paused' : 'queue.resumed', 'queue', $queue, category: 'operations', severity: 'notice', actorId: $actorId);
    }

    /**
     * Recovers jobs whose worker died (reservation expired) and marks silent workers as lost.
     *
     * @return array{requeued:int, lost_workers:int}
     */
    public function reap(): array
    {
        $requeued = $this->db->createCommand(
            "UPDATE jobs SET status = CASE WHEN attempts >= max_attempts THEN 'dead' ELSE 'pending' END,
                    last_error = 'Reservation expired (worker crashed or job exceeded its timeout)',
                    reserved_by = NULL, reserved_until = NULL,
                    available_at = now() + make_interval(secs => backoff_seconds)
             WHERE status = 'running' AND reserved_until < now()",
        )->execute();
        $lost = $this->db->createCommand(
            "UPDATE workers SET status = 'lost' WHERE status NOT IN ('stopped','lost') AND heartbeat_at < now() - interval '2 minutes'",
        )->execute();
        return ['requeued' => $requeued, 'lost_workers' => $lost];
    }

    public function find(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundException('Job not found.');
        }
        return (new Query($this->db))->from('jobs')->where(['id' => $id])->one() ?? throw new NotFoundException('Job not found.');
    }

    /** @return array<string, array{pending:int, running:int, failed:int, dead:int, paused:bool}> */
    public function stats(): array
    {
        $out = [];
        foreach ($this->db->createCommand("SELECT queue, status, count(*) c FROM jobs WHERE status IN ('pending','running','failed','dead') GROUP BY queue, status")->queryAll() as $r) {
            $out[$r['queue']] ??= ['pending' => 0, 'running' => 0, 'failed' => 0, 'dead' => 0, 'paused' => false];
            $out[$r['queue']][$r['status']] = (int) $r['c'];
        }
        foreach ((new Query($this->db))->from('queue_state')->all() as $s) {
            $out[$s['queue']] ??= ['pending' => 0, 'running' => 0, 'failed' => 0, 'dead' => 0, 'paused' => false];
            $out[$s['queue']]['paused'] = \App\Shared\Infrastructure\Database\Sql::bool($s['paused']);
        }
        ksort($out);
        return $out;
    }

    private function attempt(string $jobId, string $workerId, string $status, ?string $error, int $durationMs): void
    {
        $attempt = (int) (new Query($this->db))->select('attempts')->from('jobs')->where(['id' => $jobId])->scalar();
        $this->db->createCommand()->insert('job_attempts', [
            'job_id' => $jobId, 'attempt' => $attempt, 'worker_id' => $workerId,
            'started_at' => (new DateTimeImmutable('-' . $durationMs . ' milliseconds'))->format('Y-m-d H:i:s.uP'),
            'finished_at' => new Expression('now()'), 'status' => $status, 'error' => $error, 'duration_ms' => $durationMs,
        ])->execute();
    }
}
