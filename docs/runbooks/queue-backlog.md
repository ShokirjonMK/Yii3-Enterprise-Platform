# Runbook: queue backlog / workers not processing

Signals: Monitoring → Queue depth rising, "Oldest runnable job" > 5 min, Infrastructure → no live `queue` workers.

1. `/admin/infrastructure` → Worker processes: are heartbeats recent (< 90 s)? Dev: `docker compose ps worker`,
   `docker compose logs worker --since 10m`. K8s: `kubectl -n platform get pods -l app=worker`.
2. Paused queue? `/admin/jobs` → queues (resume).
3. Failing job type: `/admin/jobs?status=failed` — fix the cause, then retry; permanent failures are `dead`.
4. Scale workers: `/admin/infrastructure` → Kubernetes → `worker` replicas (audited) or adjust the HPA;
   dev: `docker compose up -d --scale worker=3`.
5. Stuck `running` jobs of crashed workers are recovered by `platform.queue.reap` (every minute).
6. New job types (after a deploy) are only known to workers started after the deploy — restart workers if jobs fail
   with "No handler for job type".

— Author: ShokirjonMK
