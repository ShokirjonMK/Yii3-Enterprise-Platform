# Backup & Recovery

Section 19 of the Platform Control Center (`/admin/backups`) and the procedures behind it.

## 1. Two independent backup layers

| Layer | What | Where | Restores to | Owner |
|---|---|---|---|---|
| **Physical + WAL** (primary DR mechanism) | CloudNativePG base backups (daily, from a standby) + continuous WAL archiving via `barmanObjectStore` | `s3://platform-backups/postgres` (`infra/kubernetes/base/data/postgres-cluster.yaml`, retention 30 d) | any point in time inside the retention window (PITR) | DevOps / CNPG operator |
| **Logical** (application-level, portable) | `pg_dump -Fc` of the application database, sha256, row-count snapshot | `s3://<S3_BUCKET>/backups/<YYYY-MM-DD>/<db>-<timestamp>-<id>.dump` (+ optional off-site bucket) | the moment of the dump | Platform (`BackupService`) |

Logical dumps are **not** a replacement for PITR: use them for restore drills, partial/table restores, copying data to
another environment and as an independent second copy (different tool, different format).

Local dev stack: PostgreSQL archives WAL to `/var/lib/postgresql/data/wal-archive` (`infra/docker/postgres/postgresql.conf`,
`archive_mode = on`, `archive_timeout = 300`). The admin page shows `pg_stat_archiver` (archived/failed counters, last
segment) for whatever database the application is connected to.

## 2. RPO / RTO targets

| Scenario | RPO | RTO | Mechanism |
|---|---|---|---|
| Loss of one PostgreSQL node | 0 (synchronous quorum replication) | 10–30 s | CNPG automatic failover |
| Logical corruption / bad migration / accidental delete | ≤ 5 min (`archive_timeout` 300 s; usually seconds) | 30–60 min (DB < 50 GB) | PITR into a new cluster |
| Loss of the whole cluster / region | ≤ 5 min (WAL in S3) | 2–4 h | new cluster from object store (`bootstrap.recovery`) + redeploy |
| Loss/compromise of the primary backup bucket | ≤ 24 h | 4–8 h | latest logical dump from the off-site bucket |

Targets are verified by the weekly automated restore test (§4) and the quarterly DR drill (§7). Record each drill
(date, duration, data loss) in the incident log as a sev4 "DR drill" incident with a postmortem.

## 3. Logical backups (platform)

Implementation: `src/Platform/Backup/BackupService.php`, jobs in `src/Platform/Backup/BackupJobs.php`,
registered in `config/common/jobs/ops.php`:

| Job type | Schedule (PlatformSeeder) | Action |
|---|---|---|
| `platform.backup.run` | `platform.backup` — `0 2 * * *` (disabled by default — enable in Jobs & Scheduler) | backup + retention purge |
| `platform.backup.verify_latest` | `platform.backup.verify` — `0 4 * * 0` (disabled by default) | restore test of the newest backup |
| `platform.backup.verify` | on demand ("Verify restore" button) | restore test of one backup |

What a backup does:

1. inserts a `backups` row (`status=running`);
2. opens a `REPEATABLE READ, READ ONLY` transaction, exports its snapshot (`pg_export_snapshot()`) and counts the rows
   of the key tables (`BackupService::KEY_TABLES`) **inside that snapshot**;
3. runs `pg_dump --format=custom --compress=6 --snapshot=<id>` — the dump contains exactly the data that was counted;
4. computes sha256 and size, uploads to S3 (private object), optionally server-side copies it to
   `ops.backup_offsite_bucket`;
5. stores location, checksum, size, `expires_at = now + retention.backups_days`, the row-count snapshot
   (`verify_details.source_counts`) and audits `backup.completed`.

The database password is written to a temporary **PGPASSFILE** (mode 0600, deleted in `finally`) — never on the
command line (visible in `ps`) and never in the environment. pg_dump/pg_restore run via `proc_open` with an argument
array (no shell). Failures mark the row `failed` with a redacted error and are audited.

Retention: `purgeExpired()` (runs after every scheduled backup) deletes expired objects from the primary and off-site
buckets and marks the row `expired` (the row and its audit trail are kept).

Downloads: `backup.manage` only (a dump contains every table incl. password hashes and encrypted secrets); every
download is audited (`backup.downloaded`, category security). When `S3_PUBLIC_ENDPOINT` is set the user is redirected to
a pre-signed URL valid for 5 minutes; otherwise the object is streamed through the application.

### Manual commands

```bash
# Queue a backup exactly like the UI / scheduler does (there is no dedicated console command:
# use "Start backup" in /admin/backups or enable the platform.backup schedule)

# Equivalent manual logical dump (dev stack) — password via .pgpass, not on the command line
docker compose exec app sh -c 'umask 077; printf "%s:%s:*:%s:%s\n" "$DB_HOST" 5432 "$DB_USER" "$DB_PASSWORD" > /tmp/.pgpass;
  PGPASSFILE=/tmp/.pgpass pg_dump -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -Fc -Z 6 -f /tmp/manual.dump; rm /tmp/.pgpass; ls -l /tmp/manual.dump'

# List backup objects in the dev bucket (SeaweedFS S3 API)
docker compose exec app php -r 'require "vendor/autoload.php"; App\Environment::prepare();
  $c = App\Tests\Support\TestContainer::get(); foreach ($c->get(App\Platform\Storage\ObjectStorage::class)->filesystem()->listContents("backups", true) as $f) echo $f->path(), "\n";'
```

## 4. Restore verification (automated restore test)

`BackupService::verify()`:

1. downloads the object and recomputes sha256 → mismatch = failed (tampering / corruption);
2. `CREATE DATABASE verify_<YYYYmmddHHMMSS>_<rand>`;
3. `pg_restore --no-owner --no-privileges --single-transaction --exit-on-error`;
4. counts the key tables in the restored database and compares them with the snapshot taken at backup time;
5. `DROP DATABASE … WITH (FORCE)` (always, in `finally`);
6. stores `verify_status` (`passed`/`failed`) and the full comparison in `verify_details.verification`, audits `backup.verified`.

The integration test `tests/Integration/OpsBackupRestoreTest.php` runs a real backup of `platform_test` to S3, a real
restore verification, a tamper check and the retention purge.

## 5. Restoring a logical backup

See `docs/runbooks/restore-database.md`. Summary:

```bash
# 1. Download (UI "Download", or with S3 credentials)
aws --endpoint-url "$S3_ENDPOINT" s3 cp s3://platform/backups/2026-11-04/platform-20261104T020000Z-1a2b3c4d.dump ./restore.dump
sha256sum restore.dump      # compare with the checksum shown on the backup page

# 2. Restore into a NEW database, verify, then switch the application (never restore over a live DB)
createdb -h <host> -U <admin> platform_restore
pg_restore -h <host> -U <admin> -d platform_restore --no-owner --role=platform --single-transaction --exit-on-error restore.dump

# 3. Partial restore of one table (e.g. accidentally deleted settings)
pg_restore -l restore.dump | grep ' TABLE DATA public settings ' > list.txt
pg_restore -h <host> -U <admin> -d platform_restore -L list.txt --data-only restore.dump
```

## 6. Point-in-time recovery (production, CloudNativePG)

See `docs/runbooks/pitr-recovery.md` and `infra/kubernetes/examples/postgres-pitr-recovery.example.yaml`. PITR always
restores into a **new** cluster; the application is switched over by changing `DB_HOST` (or the `-rw` service).

```yaml
apiVersion: postgresql.cnpg.io/v1
kind: Cluster
metadata: { name: platform-pg-pitr, namespace: platform }
spec:
  instances: 3
  bootstrap:
    recovery:
      source: platform-pg
      recoveryTarget:
        targetTime: "2026-11-04 09:41:00+00"     # just before the incident (UTC)
  externalClusters:
    - name: platform-pg
      barmanObjectStore:
        destinationPath: s3://platform-backups/postgres
        s3Credentials:
          accessKeyId: { name: platform-backup-s3, key: ACCESS_KEY_ID }
          secretAccessKey: { name: platform-backup-s3, key: ACCESS_SECRET_KEY }
        wal: { maxParallel: 8 }
```

```bash
kubectl apply -f platform-pg-pitr.yaml
kubectl cnpg status platform-pg-pitr -n platform          # wait for "Cluster in healthy state"
kubectl cnpg psql platform-pg-pitr -n platform -- -c "select max(occurred_at) from audit_log"
# switch: scale app/worker to 0, point DB_HOST to platform-pg-pitr-rw, scale back, verify, keep the old cluster fenced for forensics
kubectl cnpg fencing on platform-pg '*' -n platform
```

Non-Kubernetes installations (Ansible/VM): use **WAL-G** with the same bucket layout —
`wal-g backup-push $PGDATA` nightly, `archive_command = 'wal-g wal-push %p'`; recovery with
`wal-g backup-fetch $PGDATA LATEST`, `restore_command = 'wal-g wal-fetch %f %p'`, `recovery_target_time = '…'`
and an empty `recovery.signal` file.

## 7. Disaster recovery runbook (whole cluster / region lost)

1. **Declare** a sev1 incident (`/admin/incidents`), name an incident commander, freeze deployments.
2. **Provision** the target cluster (`infra/terraform`, `infra/kubernetes/overlays/<env>`), operators (CNPG, Strimzi).
3. **Restore PostgreSQL** from the object store (§6, `bootstrap.recovery` without `recoveryTarget` = latest WAL).
   If the backup bucket is unusable: restore the newest logical dump from the off-site bucket (§5) — RPO ≤ 24 h.
4. **Redis**: no restore needed (cache, sessions, rate limits are rebuilt; users sign in again).
5. **Kafka**: topics are recreated by Strimzi `KafkaTopic` resources; unpublished events are still in the PostgreSQL
   `outbox` and are re-published by the relay (consumers are idempotent through the inbox table).
6. **Object storage**: files bucket is replicated by the provider; verify `storage` health on the dashboard.
7. **Deploy** the last known good version through CI/CD (four-eyes approval still applies; break-glass superadmin can approve).
8. **Verify**: `/health/ready`, admin dashboard health, audit integrity (`/admin/audit/integrity`), a test login, a
   restore test of the next logical backup.
9. **Close** with a postmortem (RPO/RTO achieved vs. targets).

## 8. Monitoring of backups

- Admin: last successful backup, last passed restore test, WAL archiver failures (`/admin/backups`).
- Prometheus (monitoring profile): `pg_stat_archiver_failed_count` (postgres-exporter), CNPG
  `cnpg_pg_wal_archive_status`, job failures `platform_queue_jobs{status="dead"}`.
- Alert when no successful backup in 26 h or no passed restore test in 8 days (Alertmanager rules, monitoring profile).

— Author: ShokirjonMK
