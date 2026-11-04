# Runbook: restore a logical backup

**When**: data loss limited to the application database and a recent logical dump is good enough, a restore drill,
or copying production data to an isolated environment. For "restore to 09:41 today" use `pitr-recovery.md`.

1. Open a sev1/sev2 incident (or a sev4 "restore drill"), freeze deployments.
2. Pick the backup in `/admin/backups` — prefer one whose restore test is `passed`. Note its SHA-256.
3. Download (UI → Download, `backup.manage`; the download is audited) or with S3 credentials:
   ```bash
   aws --endpoint-url "$S3_ENDPOINT" s3 cp s3://<bucket>/backups/<date>/<file>.dump ./restore.dump
   sha256sum restore.dump        # must equal the checksum shown in the UI
   ```
4. Restore into a **new** database (never over the live one):
   ```bash
   umask 077; printf '%s:%s:*:%s:%s\n' "$PGHOST" 5432 "$PGADMIN" "$PGADMIN_PASSWORD" > ~/.pgpass
   createdb -h "$PGHOST" -U "$PGADMIN" platform_restore
   pg_restore -h "$PGHOST" -U "$PGADMIN" -d platform_restore --no-owner --role=platform \
              --single-transaction --exit-on-error restore.dump
   ```
5. Verify: row counts vs. the backup page (Row counts table), `select max(occurred_at) from audit_log;`, a test login
   against a staging app pointed at `platform_restore`.
6. Switch: scale `app`, `worker`, `scheduler`, `relay`, `consumer` to 0 → rename databases
   (`ALTER DATABASE platform RENAME TO platform_broken; ALTER DATABASE platform_restore RENAME TO platform;`) → scale up.
7. Verify `/health/ready`, audit integrity (`/admin/audit/integrity`), then resolve the incident and write the postmortem.
8. Delete `~/.pgpass` and the local dump file.

Partial restore (single table): `pg_restore -l restore.dump | grep 'TABLE DATA public <table>' > list.txt` then
`pg_restore -L list.txt --data-only -d platform_restore restore.dump` and copy the needed rows with `INSERT … SELECT`.

— Author: ShokirjonMK
