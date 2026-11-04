# Runbook: point-in-time recovery (CloudNativePG)

**When**: logical corruption, a destructive migration or mass deletion at a known time. PITR restores into a new
cluster from the base backup + archived WAL in `s3://platform-backups/postgres`.

1. Sev1 incident, IC assigned, deployments frozen. Determine the target time (UTC) — e.g. from the audit log:
   `/admin/audit?action=<bad action>` → note `occurred_at` and subtract a few seconds.
2. Stop writers to limit divergence: `kubectl -n platform scale deploy/worker deploy/scheduler deploy/relay deploy/consumer --replicas=0`.
3. Create the recovery cluster from `infra/kubernetes/examples/postgres-pitr-recovery.example.yaml`
   (`bootstrap.recovery.recoveryTarget.targetTime: "<time>+00"`), then:
   ```bash
   kubectl apply -f platform-pg-pitr.yaml
   kubectl cnpg status platform-pg-pitr -n platform
   kubectl cnpg psql platform-pg-pitr -n platform -- -c "select max(occurred_at) from audit_log"
   ```
4. Validate the data (counts of affected tables, a sample of records).
5. Switch the application: update `DB_HOST` (and `DB_REPLICAS`) in the platform ConfigMap/Secret to
   `platform-pg-pitr-rw` / `platform-pg-pitr-ro`, `kubectl -n platform rollout restart deploy/app`, scale writers back up.
6. Fence the old cluster for forensics: `kubectl cnpg fencing on platform-pg '*' -n platform` (delete only after the postmortem).
7. Re-enable backups on the new cluster (ScheduledBackup pointing at it) and run a logical backup + restore test from `/admin/backups`.

Without Kubernetes (WAL-G): stop PostgreSQL, move `$PGDATA` aside, `wal-g backup-fetch $PGDATA LATEST`, set
`restore_command = 'wal-g wal-fetch %f %p'` and `recovery_target_time = '<time>'`, `touch $PGDATA/recovery.signal`, start,
check, `select pg_wal_replay_resume();`.

— Author: ShokirjonMK
