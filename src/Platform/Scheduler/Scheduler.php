<?php

declare(strict_types=1);

namespace App\Platform\Scheduler;

use App\Platform\Audit\AuditLogger;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Lock\PostgresAdvisoryLock;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Cron scheduler. The scheduler only *enqueues* jobs; workers execute them.
 *
 * Exactly-once per tick, even with several scheduler replicas:
 *  1. a PostgreSQL advisory lock elects one active scheduler (others stay hot-standby),
 *  2. `schedule_runs (schedule_id, scheduled_for)` is UNIQUE — a tick can be recorded only once,
 *  3. overlap = "skip" refuses to enqueue while the previous job of the schedule is still pending/running.
 */
final class Scheduler
{
    private const LEADER_LOCK = 'platform:scheduler:leader';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly JobQueue $queue,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {}

    public function acquireLeadership(): bool
    {
        return (new PostgresAdvisoryLock($this->db))->acquire(self::LEADER_LOCK, 0) !== null;
    }

    /** @return int number of jobs enqueued */
    public function tick(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $enqueued = 0;
        $due = (new Query($this->db))->from('schedules')
            ->where(['enabled' => true])
            ->andWhere(['or', ['next_run_at' => null], ['<=', 'next_run_at', $now->format('Y-m-d H:i:sP')]])
            ->all();

        foreach ($due as $s) {
            try {
                $scheduledFor = $s['next_run_at'] !== null ? new DateTimeImmutable((string) $s['next_run_at']) : $now;
                $next = $this->nextRun((string) $s['cron_expression'], (string) $s['timezone'], $now);

                $status = 'enqueued';
                $jobId = null;
                $message = null;
                if ($s['overlap'] === 'skip' && $s['last_job_id'] !== null
                    && (new Query($this->db))->from('jobs')->where(['id' => $s['last_job_id']])->andWhere(['in', 'status', ['pending', 'running']])->exists()) {
                    $status = 'skipped';
                    $message = 'Previous run still in progress';
                }

                $this->db->transaction(function () use ($s, $scheduledFor, $next, &$status, &$jobId, $message): void {
                    $claimed = $this->db->createCommand(
                        'INSERT INTO schedule_runs (schedule_id, scheduled_for, status, message, host) VALUES (:s, :f, :st, :m, :h)
                         ON CONFLICT (schedule_id, scheduled_for) DO NOTHING RETURNING id',
                        [':s' => $s['id'], ':f' => $scheduledFor->format('Y-m-d H:i:sP'), ':st' => $status, ':m' => $message, ':h' => gethostname()],
                    )->queryScalar();
                    if ($claimed === false || $claimed === null) {
                        // another scheduler already handled this tick: only make sure the schedule moves on
                        $status = 'duplicate';
                        $this->db->createCommand()->update('schedules', ['next_run_at' => $next->format('Y-m-d H:i:sP')], ['id' => $s['id']])->execute();
                        return;
                    }
                    if ($status === 'enqueued') {
                        $payload = json_decode((string) $s['payload'], true) ?: [];
                        $jobId = $this->queue->push((string) $s['job_type'], $payload + ['_schedule' => $s['code']], [
                            'queue' => (string) $s['queue'],
                            'idempotency_key' => 'schedule:' . $s['id'] . ':' . $scheduledFor->format('U'),
                        ]);
                        $this->db->createCommand()->update('schedule_runs', ['job_id' => $jobId], ['id' => $claimed])->execute();
                    }
                    $this->db->createCommand()->update('schedules', [
                        'last_run_at' => new Expression('now()'),
                        'next_run_at' => $next->format('Y-m-d H:i:sP'),
                        'last_status' => $status,
                        'last_error' => null,
                        'last_job_id' => $jobId ?? new Expression('last_job_id'),
                    ], ['id' => $s['id']])->execute();
                });
                if ($status === 'enqueued') {
                    $enqueued++;
                }
            } catch (Throwable $e) {
                $this->logger->error('Schedule {code} failed: {e}', ['code' => $s['code'], 'e' => $e->getMessage()]);
                $this->db->createCommand()->update('schedules', ['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $s['id']])->execute();
            }
        }
        return $enqueued;
    }

    public function nextRun(string $expression, string $timezone, DateTimeImmutable $after): DateTimeImmutable
    {
        if (!CronExpression::isValidExpression($expression)) {
            throw ValidationException::single('cron_expression', 'Invalid cron expression.');
        }
        $cron = new CronExpression($expression);
        $local = $after->setTimezone(new DateTimeZone($timezone));
        return DateTimeImmutable::createFromMutable($cron->getNextRunDate($local->format('Y-m-d H:i:s'), 0, false, $timezone))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return list<string> next N run times (for the admin preview) */
    public function preview(string $expression, string $timezone, int $count = 5): array
    {
        $out = [];
        $t = new DateTimeImmutable();
        for ($i = 0; $i < $count; $i++) {
            $t = $this->nextRun($expression, $timezone, $t);
            $out[] = $t->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i T');
        }
        return $out;
    }

    public function save(string $actorId, ?string $id, array $input, array $knownJobTypes): string
    {
        $errors = [];
        $code = trim((string) ($input['code'] ?? ''));
        $cron = trim((string) ($input['cron_expression'] ?? ''));
        $tz = trim((string) ($input['timezone'] ?? 'Asia/Tashkent'));
        $type = (string) ($input['job_type'] ?? '');
        if ($id === null && !preg_match('/^[a-z0-9][a-z0-9_.-]{1,95}$/', $code)) {
            $errors['code'][] = 'Code: 2–96 lowercase letters, digits, . _ -';
        }
        if (!CronExpression::isValidExpression($cron)) {
            $errors['cron_expression'][] = 'Invalid cron expression.';
        }
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'][] = 'Unknown time zone.';
        }
        if (!in_array($type, $knownJobTypes, true)) {
            $errors['job_type'][] = 'Unknown job type.';
        }
        $payload = $input['payload'] ?? '{}';
        $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);
        if (!is_array($decoded)) {
            $errors['payload'][] = 'Payload must be a JSON object.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $row = [
            'name' => mb_substr(trim((string) ($input['name'] ?? $code)), 0, 255) ?: $code,
            'cron_expression' => $cron,
            'timezone' => $tz,
            'job_type' => $type,
            'payload' => json_encode($decoded, JSON_FORCE_OBJECT),
            'queue' => preg_replace('/[^a-z0-9_-]/', '', (string) ($input['queue'] ?? 'default')) ?: 'default',
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'overlap' => ($input['overlap'] ?? 'skip') === 'allow' ? 'allow' : 'skip',
            'next_run_at' => $this->nextRun($cron, $tz, new DateTimeImmutable())->format('Y-m-d H:i:sP'),
        ];
        return $this->db->transaction(function () use ($id, $code, $row, $actorId): string {
            if ($id === null) {
                $row['code'] = $code;
                $newId = (string) $this->db->createCommand('INSERT INTO schedules (code, name, cron_expression, timezone, job_type, payload, queue, enabled, overlap, next_run_at)
                    VALUES (:code, :name, :cron_expression, :timezone, :job_type, CAST(:payload AS jsonb), :queue, :enabled, :overlap, :next_run_at) RETURNING id',
                    array_combine(array_map(static fn($k) => ':' . $k, array_keys($row)), array_values($row)))->queryScalar();
                $this->audit->record('schedule.created', 'schedule', $newId, null, $row, category: 'operations', actorId: $actorId);
                return $newId;
            }
            $before = (new Query($this->db))->from('schedules')->where(['id' => $id])->one() ?? throw new NotFoundException('Schedule not found.');
            $this->db->createCommand()->update('schedules', $row, ['id' => $id])->execute();
            $this->audit->record('schedule.updated', 'schedule', $id, $before, $row, category: 'operations', actorId: $actorId);
            return $id;
        });
    }

    public function setEnabled(string $actorId, string $id, bool $enabled): void
    {
        $s = (new Query($this->db))->from('schedules')->where(['id' => $id])->one() ?? throw new NotFoundException('Schedule not found.');
        $data = ['enabled' => $enabled];
        if ($enabled) {
            $data['next_run_at'] = $this->nextRun((string) $s['cron_expression'], (string) $s['timezone'], new DateTimeImmutable())->format('Y-m-d H:i:sP');
        }
        $this->db->createCommand()->update('schedules', $data, ['id' => $id])->execute();
        $this->audit->record($enabled ? 'schedule.enabled' : 'schedule.disabled', 'schedule', $id, category: 'operations', actorId: $actorId);
    }

    /** Runs a schedule now (manual trigger) — goes through the queue like a normal tick. */
    public function runNow(string $actorId, string $id): string
    {
        $s = (new Query($this->db))->from('schedules')->where(['id' => $id])->one() ?? throw new NotFoundException('Schedule not found.');
        $jobId = $this->queue->push((string) $s['job_type'], (json_decode((string) $s['payload'], true) ?: []) + ['_schedule' => $s['code'], '_manual' => true], ['queue' => (string) $s['queue']]);
        $this->db->createCommand()->update('schedules', ['last_job_id' => $jobId, 'last_run_at' => new Expression('now()'), 'last_status' => 'manual'], ['id' => $id])->execute();
        $this->audit->record('schedule.run_now', 'schedule', $id, null, ['job_id' => $jobId], category: 'operations', actorId: $actorId);
        return $jobId;
    }
}
