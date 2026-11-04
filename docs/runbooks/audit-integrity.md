# Runbook: audit integrity verification failed

`/admin/audit/integrity` → "Verify signatures" reported records with an INVALID HMAC, i.e. a stored audit row no
longer matches its signature (direct database modification, restore of a manipulated dump, or APP_SECRET change).

1. Treat as a **sev1 security incident**; restrict access to the database (rotate DB credentials, review `pg_hba`/network policies).
2. Do not modify the affected rows. Record the invalid ids (shown on the page and in the `audit.verified` audit record).
3. Check whether `APP_SECRET` was changed: the HMAC key is derived from it — a changed secret invalidates *all* rows
   (then restore the previous secret from the secret manager).
4. Compare the affected rows with the latest logical backup restored into a scratch database (`restore-database.md`)
   to find what changed; the trigger `audit_log_no_update` must still exist: `\d+ audit_log` → triggers.
5. Review PostgreSQL logs for `ALTER TABLE … DISABLE TRIGGER` or superuser sessions in the time window.
6. Postmortem with security officer; keep the evidence (detached copies, logs) per retention policy.

— Author: ShokirjonMK
