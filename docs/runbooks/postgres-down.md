# Runbook: PostgreSQL down or degraded

Automatic incident `health:postgres` (sev1) is opened after two failed probes. Note: while PostgreSQL is down the
incident itself cannot be stored — rely on Alertmanager (`PostgresDown`) and the Telegram alert chat.

1. Confirm: `/health/ready` (503), dashboard health, `kubectl cnpg status platform-pg -n platform`
   (dev: `docker compose ps postgres`, `docker compose logs postgres --since 10m`).
2. **Failover in progress?** CNPG promotes a synchronous standby within 10–30 s; the app reconnects automatically.
   Check `kubectl -n platform get pods -l cnpg.io/cluster=platform-pg -L role`.
3. **Connections exhausted** (`too many connections`): `select usename, state, count(*) from pg_stat_activity group by 1,2;`
   terminate idle-in-transaction sessions older than 5 min:
   `select pg_terminate_backend(pid) from pg_stat_activity where state = 'idle in transaction' and state_change < now() - interval '5 minutes';`
4. **Disk full**: `kubectl cnpg status` shows PVC usage; WAL archiving failures (`/admin/backups` → WAL archiving)
   cause WAL accumulation — fix the archive target first, then expand the PVC.
5. **Replication lag** on standbys: `select application_name, replay_lag from pg_stat_replication;` reads stick to the
   primary for `DB_REPLICA_STICKINESS` seconds, so lag mainly affects reports.
6. When healthy, the next probe resolves the incident; write the postmortem.

— Author: ShokirjonMK
