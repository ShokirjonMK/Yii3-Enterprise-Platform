# Background workers, job queue and scheduler

The platform runs background work on a **durable PostgreSQL job queue** (`jobs` table, `FOR UPDATE SKIP LOCKED`)
with cron **schedules** that only *enqueue* jobs. Admin UI: **Control Center → 10 · Jobs & Scheduler** (`/admin/jobs`).

| Component | Code | Process |
|---|---|---|
| Queue | `src/Platform/Queue/JobQueue.php` | — (library) |
| Worker | `src/Platform/Queue/Worker.php` | `php yii queue:work` |
| Handler registry | `src/Platform/Queue/HandlerRegistry.php`, `config/common/jobs.php` + `config/common/jobs/*.php` | — |
| Scheduler | `src/Platform/Scheduler/Scheduler.php` | `php yii scheduler:run --daemon` |
| Admin UI | `src/Admin/Jobs/*`, `src/Admin/Jobs/views/*`, `src/Admin/Jobs/routes.php` | PHP-FPM |

Why PostgreSQL and not `yiisoft/queue`: no stable release of yiisoft/queue yet, and a database queue gives
**transactional enqueue** — a job pushed inside a business transaction exists if and only if the transaction commits.
High-volume event streams go through Kafka (see [KAFKA.md](KAFKA.md)).

## 1. Commands

```bash
# worker (one process = one concurrent job; scale processes/replicas for concurrency)
php yii queue:work --queue=default,notifications,integrations --sleep=1 --max-jobs=1000 --memory=256
php yii queue:work --queue=default --once          # drain once and exit (CI, debugging)
php yii worker:health --type=queue                 # liveness probe: exit 0 if a fresh heartbeat exists

# scheduler (run ≥ 2 replicas; only the advisory-lock leader enqueues)
php yii scheduler:run --daemon                     # tick every 15 s
php yii scheduler:run                              # single tick
```
In Docker: services `worker` and `scheduler` (`compose.yaml`). In Kubernetes: Deployments `worker`
(autoscaled by KEDA on pending jobs) and `scheduler` (2 replicas) — see [DEPLOYMENT.md](DEPLOYMENT.md).

## 2. Writing a job

```php
// src/Modules/Projects/config/jobs.php
return ['projects.report.generate' => ['handler' => GenerateReportJob::class, 'queue' => 'default']];

final class GenerateReportJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $context): void
    {
        foreach ($chunks as $i => $chunk) {
            $context->throwIfCancelled();          // cooperative cancel from the admin panel
            // ... idempotent work keyed by $context->jobId ...
            $context->progress((int) (($i + 1) / $total * 100));
        }
    }
}

$jobs->push('projects.report.generate', ['project_id' => $id], ['idempotency_key' => "report:$id:$day", 'delay' => 0, 'max_attempts' => 5, 'timeout' => 300]);
```
Rules: handlers **must be idempotent** (at-least-once delivery after crashes/timeouts); never put secrets in the
payload (payloads are shown — redacted — in the admin UI); throw `PermanentJobFailure` for non-retryable errors.

## 3. Lifecycle and guarantees

`pending → running → succeeded | pending (retry) | dead | cancelled`

- **Reservation:** `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED LIMIT 1)`; ordered by `priority, available_at`;
  paused queues (`queue_state.paused`) are skipped.
- **Retry:** exponential backoff `backoff × 2^(attempt-1)` (max 1 h) + jitter; after `max_attempts` → `dead`.
  Every attempt is stored in `job_attempts` (worker, duration, redacted error).
- **Timeout:** `pcntl_alarm(timeout_seconds)` per job; reservation lease = timeout + 30 s.
- **Crash recovery:** `platform.queue.reap` (scheduled) re-queues jobs whose lease expired and marks workers whose
  heartbeat is older than 2 min as `lost`.
- **Graceful shutdown:** SIGTERM/SIGINT finish the current job, mark the worker `stopped`, exit. Use a
  `terminationGracePeriodSeconds`/`stop_grace_period` ≥ the longest job timeout you care about (compose: 40 s).
- **Recycling:** the worker exits after `--max-jobs` or `--memory` MB; the supervisor restarts it.
- **Cancel:** pending → `cancelled` immediately; running → `cancel_requested=true`, handler stops at its next
  `throwIfCancelled()`.

## 4. Scheduler — exactly once per tick

1. PostgreSQL advisory lock `platform:scheduler:leader` elects one active scheduler (others are hot standby).
2. `schedule_runs (schedule_id, scheduled_for)` is UNIQUE — a tick can be recorded only once even if two leaders overlap.
3. The enqueued job has idempotency key `schedule:<id>:<unix tick>`.
4. `overlap = skip` refuses to enqueue while the previous job of the schedule is still pending/running (recorded as `skipped`).

Cron syntax is `dragonmantank/cron-expression` (5 fields, `@hourly`, `@daily` …), evaluated in the schedule's time zone.

## 5. Admin panel (10 · Jobs & Scheduler)

| Screen | Route | Permission |
|---|---|---|
| Queue stats + pause/resume, job list (filters queue/status/type/search, sortable, HTMX auto-refresh 10 s) | `admin.jobs` | `jobs.view` (pause: `jobs.manage`) |
| Job detail: redacted payload, attempts, last error, progress (auto-refresh while pending/running); retry / cancel | `admin.jobs.view` | `jobs.view` / `jobs.manage` |
| Failed & dead jobs, bulk retry (selected or all matching, max 500) | `admin.jobs.failed`, `admin.jobs.retry_bulk` | `jobs.view` / `jobs.manage` |
| Workers: alive / lost / stopped, heartbeat, memory, current job | `admin.jobs.workers` | `jobs.view` |
| Schedules: list, create/edit with live cron preview (`Scheduler::preview`), enable/disable, run now, recent `schedule_runs` | `admin.jobs.schedules*` | `jobs.view` / `jobs.manage` |

Every operator action is audited (`job.retried`, `job.cancelled`, `job.bulk_retried`, `queue.paused/resumed`,
`schedule.created/updated/enabled/disabled/run_now`).

## 6. Operations

| Symptom | Check | Action |
|---|---|---|
| Pending grows, nothing runs | Workers screen: any *alive* worker for the queue? queue paused? | resume queue; scale `worker`; check logs `docker compose logs worker` |
| Jobs stuck `running` | lease (`reserved_until`) in the past? | wait for `platform.queue.reap` (or run the schedule now) |
| Many `dead` jobs | job detail → attempts/error | fix root cause, then *Failed & dead → Retry all matching* |
| Schedule did not fire | Schedules → last status/error, recent runs (`skipped`/`duplicate`) | check `scheduler` logs; only one leader enqueues |

Metrics: `jobs_processed_total{queue,type,status}`, `job_duration_seconds` (see [MONITORING.md](MONITORING.md)).

— Author: ShokirjonMK
