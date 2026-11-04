<?php

declare(strict_types=1);

namespace App\Admin\Action\Jobs;

use App\Platform\Queue\HandlerRegistry;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Platform\Database\SqlSearch;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Read side of Admin → Jobs & Scheduler (lists, filters, workers, schedule runs).
 * Writes go through JobQueue / Scheduler (which audit every operator action).
 */
final class JobsReadModel
{
    public const STATUSES = ['pending', 'running', 'succeeded', 'failed', 'cancelled', 'dead'];
    public const SORTABLE = [
        'created_at' => 'created_at',
        'available_at' => 'available_at',
        'finished_at' => 'finished_at',
        'priority' => 'priority',
        'attempts' => 'attempts',
        'type' => 'type',
        'queue' => 'queue',
        'status' => 'status',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly HandlerRegistry $handlers,
    ) {}

    /**
     * @param array{queue?:string, status?:string|list<string>, type?:string, q?:string} $filters
     */
    public function jobs(array $filters, int $page, ?string $sort, int $perPage = 25): Page
    {
        $q = (new Query($this->db))
            ->select(['id', 'queue', 'type', 'status', 'priority', 'attempts', 'max_attempts', 'progress', 'available_at', 'started_at', 'finished_at', 'created_at', 'cancel_requested', 'reserved_by',
                'last_error' => new Expression('left(last_error, 300)')])
            ->from('jobs');
        $this->applyFilters($q, $filters);
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-created_at')
            ->map(static function (array $r): array {
                $r['last_error'] = $r['last_error'] === null ? null : Redactor::redactString((string) $r['last_error']);
                return $r;
            });
    }

    /** @return list<string> ids matching the filters (bounded) */
    public function ids(array $filters, int $limit = 500): array
    {
        $q = (new Query($this->db))->select('id')->from('jobs')->orderBy(['created_at' => SORT_ASC])->limit($limit);
        $this->applyFilters($q, $filters);
        return array_map('strval', $q->column());
    }

    /** @return array<string,mixed> job with redacted payload */
    public function job(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundException('Job not found.');
        }
        $job = (new Query($this->db))->from('jobs')->where(['id' => $id])->one() ?? throw new NotFoundException('Job not found.');
        $job['payload'] = Redactor::redact(Sql::json($job['payload'], []));
        $job['last_error'] = $job['last_error'] === null ? null : Redactor::redactString((string) $job['last_error']);
        return $job;
    }

    /** @return list<array<string,mixed>> */
    public function attempts(string $jobId): array
    {
        return array_map(static function (array $a): array {
            $a['error'] = $a['error'] === null ? null : Redactor::redactString((string) $a['error']);
            return $a;
        }, (new Query($this->db))->from('job_attempts')->where(['job_id' => $jobId])->orderBy(['attempt' => SORT_DESC, 'id' => SORT_DESC])->limit(100)->all());
    }

    /** @return list<string> */
    public function queues(): array
    {
        $names = array_merge(
            $this->db->createCommand('SELECT DISTINCT queue FROM jobs UNION SELECT queue FROM queue_state UNION SELECT queue FROM schedules')->queryColumn(),
            array_map(fn($t) => $this->handlers->defaultQueue($t), $this->handlers->types()),
        );
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names);
        return $names;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->handlers->types();
    }

    /** @return array<string,int> status => count (last 24 h for finished states) */
    public function totals(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->createCommand(
            "SELECT status, count(*) c FROM jobs WHERE status IN ('pending','running','failed','dead') OR finished_at > now() - interval '24 hours' GROUP BY status",
        )->queryAll() as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * Workers with derived liveness: alive (fresh heartbeat), lost (stale heartbeat or reaped), stopped.
     *
     * @return list<array<string,mixed>>
     */
    public function workers(string $filter = 'all'): array
    {
        $q = (new Query($this->db))
            ->select([
                'w.*',
                'job_type' => 'j.type',
                'heartbeat_age' => new Expression('EXTRACT(EPOCH FROM now() - w.heartbeat_at)::int'),
                'liveness' => new Expression("CASE WHEN w.status = 'stopped' THEN 'stopped'
                    WHEN w.status = 'lost' OR w.heartbeat_at < now() - interval '90 seconds' THEN 'lost' ELSE 'alive' END"),
            ])
            ->from(['w' => 'workers'])
            ->leftJoin(['j' => 'jobs'], 'j.id = w.current_job_id')
            ->orderBy([new Expression("CASE WHEN w.status = 'stopped' THEN 2 WHEN w.heartbeat_at < now() - interval '90 seconds' THEN 1 ELSE 0 END"), 'w.heartbeat_at' => SORT_DESC])
            ->limit(200);
        $rows = $q->all();
        if ($filter !== 'all') {
            $rows = array_values(array_filter($rows, static fn($r) => $r['liveness'] === $filter));
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function schedules(): array
    {
        return (new Query($this->db))
            ->select(['s.*', 'last_job_status' => 'j.status'])
            ->from(['s' => 'schedules'])
            ->leftJoin(['j' => 'jobs'], 'j.id = s.last_job_id')
            ->orderBy(['s.code' => SORT_ASC])
            ->all();
    }

    /** @return array<string,mixed> */
    public function schedule(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundException('Schedule not found.');
        }
        return (new Query($this->db))->from('schedules')->where(['id' => $id])->one() ?? throw new NotFoundException('Schedule not found.');
    }

    /** @return list<array<string,mixed>> */
    public function runs(?string $scheduleId = null, int $limit = 30): array
    {
        $q = (new Query($this->db))
            ->select(['r.*', 'code' => 's.code', 'job_status' => 'j.status'])
            ->from(['r' => 'schedule_runs'])
            ->innerJoin(['s' => 'schedules'], 's.id = r.schedule_id')
            ->leftJoin(['j' => 'jobs'], 'j.id = r.job_id')
            ->orderBy(['r.created_at' => SORT_DESC])
            ->limit($limit);
        if ($scheduleId !== null) {
            $q->where(['r.schedule_id' => $scheduleId]);
        }
        return $q->all();
    }

    private function applyFilters(Query $q, array $filters): void
    {
        if (($filters['queue'] ?? '') !== '') {
            $q->andWhere(['queue' => (string) $filters['queue']]);
        }
        $status = $filters['status'] ?? '';
        if (is_array($status)) {
            $status = array_values(array_intersect($status, self::STATUSES));
            if ($status !== []) {
                $q->andWhere(['status' => $status]);
            }
        } elseif ($status !== '' && in_array($status, self::STATUSES, true)) {
            $q->andWhere(['status' => $status]);
        }
        if (($filters['type'] ?? '') !== '') {
            $q->andWhere(['type' => (string) $filters['type']]);
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            Uuid::isValid($term)
                ? $q->andWhere(['id' => $term])
                : $q->andWhere(SqlSearch::contains($term, ['type', 'last_error', 'correlation_id', 'idempotency_key']));
        }
    }
}
