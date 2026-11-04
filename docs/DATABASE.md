# PostgreSQL & Redis data layer

PostgreSQL 17 is the **system of record**. Redis 7.4 is a cache/coordination layer only. Admin UI:
**12 · PostgreSQL Management** (`/admin/postgres`), **13 · Redis Management** (`/admin/redis`),
**14 · Storage & Search** (`/admin/storage`). Cluster operation (CloudNativePG, Redis Sentinel, backups) is
documented by the infrastructure team in [DEPLOYMENT.md](DEPLOYMENT.md) and [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md).

## 1. Connections and read/write splitting

| Env | Meaning |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE` | primary (read-write) |
| `DB_REPLICAS=host:port[,host:port]` | read replicas for `DatabaseRouter::read()` (empty = primary only) |
| `DB_REPLICA_STICKINESS` (5) | seconds reads stay on the primary after a write in the same process |
| `DB_STATEMENT_TIMEOUT_MS` (30000) | server-side statement timeout |
| `DB_TEST_NAME` (platform_test) | database used by `APP_ENV=test` |

`DatabaseRouter` (`src/Shared/Infrastructure/Database/DatabaseRouter.php`) returns the primary inside transactions and
within the stickiness window, picks a random healthy replica otherwise, and skips a failing replica for 30 s.
`replicaStatus()` (shown on the PostgreSQL overview / replication pages) reports health and replay lag per replica.

## 2. Migrations

```bash
php yii migrate:up --no-interaction       # also run by platform:install and the deployment Job
php yii migrate:history
APP_ENV=test php yii migrate:up           # test database (the test bootstrap does this automatically)
```
Migrations live in `migrations/` (`M<yymmddhhmmss><Name>`, raw SQL via `SqlScript::run`). Zero-downtime rules
(expand → migrate → contract, `CREATE INDEX CONCURRENTLY` in non-transactional migrations) are in [DEPLOYMENT.md](DEPLOYMENT.md) §6.

## 3. Partitioning

`audit_log` is range-partitioned by month (`audit_log_pYYYYMM` + `audit_log_default`). Job
`platform.maintenance.partitions` (scheduled; also *Run partition maintenance* on the Partitions page) calls
`platform_ensure_monthly_partitions('audit_log', 0, 3)` to create the next 3 months and **detaches** (never drops)
partitions older than `retention.audit_months`; detached tables are kept for archival export. The default partition
should stay empty — rows there mean maintenance did not run in time.

## 4. Extensions

Installed by `infra/docker/postgres/init/01-extensions.sql` (dev) / the CNPG bootstrap (prod):
`pg_stat_statements` (needs `shared_preload_libraries`; dev main DB only), `pgcrypto`, `pg_trgm`, `citext`.
The UI degrades gracefully: without `pg_stat_statements` the *Slow queries* tab shows **Not configured** with the reason.

Search uses PostgreSQL only: `pg_trgm` GIN indexes for case-insensitive "contains" and `tsvector` for full-text.
*Storage & Search → Search diagnostics* lists extensions and trigram/full-text indexes and explains a term
(`show_trgm`, `to_tsvector`, the plan of the users search and whether an index is used).

## 5. Admin operations (12 · PostgreSQL Management)

Read-only (`db.view`): version/uptime/size, cache hit ratio, connections by state, sessions (`pg_stat_activity`;
**query text has string literals masked and is truncated**), long-running queries, blocking chains and lock modes,
`pg_stat_statements` top queries, table/index sizes, unused indexes, dead-tuple bloat estimate, streaming standbys
(`pg_stat_replication`), replication slots (retained WAL), WAL archiver (`pg_stat_archiver`), application replicas,
`audit_log` partitions. Implementation: `src/Platform/Database/PostgresInspector.php`.

Safe operations (`db.manage` + step-up re-authentication, all audited):

| Operation | Guard |
|---|---|
| `ANALYZE` / `VACUUM (ANALYZE)` of one table | table must exist in `pg_stat_user_tables`; identifier quoted; never in a transaction |
| `pg_cancel_backend(pid)` | only `client backend`s of the **application role** in the **current database**, never the own session; refusals are audited (`result=denied`) and return 403 |
| Partition maintenance | enqueues `platform.maintenance.partitions` (max once per minute) |

There is intentionally **no** terminate, `VACUUM FULL`, `REINDEX`, `DROP` or `TRUNCATE` in the UI — those belong to
reviewed migrations or DBA runbooks.

## 6. High availability, backups and PITR (overview)

- **Kubernetes (recommended): CloudNativePG** — 1 primary + 2 replicas, synchronous replication (quorum), automatic
  failover with the `-rw` service always pointing at the primary and `-ro` at replicas (`DB_HOST` → `-rw`,
  `DB_REPLICAS` → `-ro`). Continuous WAL archiving + base backups to S3 (barmanObjectStore), `ScheduledBackup` CRs.
- **VMs: Patroni** (etcd/Consul DCS) + HAProxy/PgBouncer giving the same rw/ro endpoints; pgBackRest for WAL archiving
  and backups. The application only needs the two endpoints; it never decides who is primary.
- **PITR:** restore a base backup into a *new* cluster and replay archived WAL up to `targetTime`
  (CNPG `bootstrap.recovery.recoveryTarget`; Patroni/pgBackRest `--type=time`). Verify with `php yii audit:verify`
  (hash chain of the audit log), then repoint `DB_HOST`/`DB_REPLICAS`. The Replication tab shows `archive_mode` and
  archiver failures — **`archive_mode=off` (dev) means PITR is impossible on that node**.
- Connection pooling: PgBouncer in transaction mode is compatible (no session-level state is relied upon except
  advisory locks taken and released within one request/tick by the scheduler leader, which must connect directly or via session pooling).

Exact manifests, RPO/RTO targets and the restore runbook: [DEPLOYMENT.md](DEPLOYMENT.md) §4.1 and §9, [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md).

## 7. Redis

| Env | Meaning |
|---|---|
| `REDIS_MODE` | `standalone` \| `sentinel` \| `cluster` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB` | standalone endpoint |
| `REDIS_SENTINELS=h:p,…`, `REDIS_SENTINEL_SERVICE` | Sentinel discovery (HA) |
| `REDIS_PREFIX` (`yep:`) | key prefix; tests use `<prefix>test:` and DB 15 |

Namespaces under the prefix: `cache:` (PSR-16 cache), `sess:` (sessions), `lock:` (locks), `rl:` (rate limits),
`metrics:` (Prometheus counters). FLUSHALL and DEBUG are renamed off in `infra/docker/redis/redis.conf`.

**Outage policy** (also shown on the Redis page):
- cache — **fail-open**: errors become misses, backend bypassed for 10 s (`FailSafeCache`), data recomputed from PostgreSQL;
- sessions — **fail-closed**: no session can be read or written; users sign in again after recovery;
- locks — efficiency only; correctness-critical exclusion (scheduler leader) uses PostgreSQL advisory locks;
- rate limits — API limits fail open (logged); login throttling is also enforced in PostgreSQL.

Admin (`src/Platform/Redis/RedisInspector.php`): INFO (memory, hit rate, evictions, persistence, replication, keyspace),
SLOWLOG (arguments masked), LATENCY LATEST, topology (no credentials), key browser with **SCAN only** inside the
application prefix (type, TTL, memory; values of `sess:`/`lock:`/`rl:`/secret-looking keys or values are never shown),
and *Clear cache* (`redis.manage` + step-up) which SCAN+UNLINKs `<prefix>cache:<sub>*` only — sessions and locks are
untouched. In cluster mode SCAN/INFO address one node only (UNVERIFIED — no cluster in dev).

> Note: `yiisoft/router-fastroute` caches compiled routes in the PSR-16 cache (`cache:routes-cache`). After adding
> route files, clear the cache namespace (Redis page) or delete that key.

— Author: ShokirjonMK
