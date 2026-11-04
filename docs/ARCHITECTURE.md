# Architecture

## 1. Style

**Modular monolith + DDD-style boundaries.** One deployable (PHP-FPM web + worker processes from the same image),
internally split into modules with explicit application services. Any business module can later be extracted into a
service because it communicates through (a) its application service API and (b) versioned domain events on Kafka.

```
                         ┌──────────────────────────── one image ─────────────────────────────┐
  Browser ──► nginx ──► │ PHP-FPM  ─ Web (HTML+HTMX)  ┐                                         │
  API client ─────────► │          ─ Admin (/admin)   ├─► Application services ─► Domain ─► Infra │
                         │          ─ REST /api/v1     ┘          │                   │          │
                         │ worker      queue:work  ───────────────┤                   ├─► PostgreSQL (primary / replicas)
                         │ scheduler   scheduler:run --daemon ────┤                   ├─► Redis (cache, sessions, locks, rate limits)
                         │ relay       outbox:relay --daemon ─────┤  outbox ─────────►├─► Kafka (events, retry, DLQ)
                         │ consumer    kafka:consume --group=… ───┘                   └─► S3 (files, backups)
                         └─────────────────────────────────────────────────────────────────────┘
```

## 2. Layers

| Layer | Location | Rules |
|---|---|---|
| Interfaces | `src/Admin`, `src/Web`, `src/Api/V1`, `src/Console` | Parse input, call one application service, render. No SQL, no business rules. Actions and views live in separate trees (`src/**/Action`, `resources/views`). |
| Application | `src/Platform/*/Application`, `src/Platform/<Module>/*Service.php`, `src/Modules/*/Application` | Use cases: authorization (`AccessService::assert`), validation, transactions, audit, outbox events, jobs. Shared by Web, Admin, API and console. |
| Domain | `src/Platform/*/Domain`, `src/Modules/*/Domain`, `src/Shared/Domain` | Entities/value objects/state machines, domain exceptions. No framework dependencies. |
| Infrastructure | `src/Shared/Infrastructure`, `src/Platform/*/Infrastructure`, adapters | DB (yiisoft/db), Redis (Predis), Kafka (ext-rdkafka), HTTP (Guzzle), S3 (Flysystem), mail (symfony/mailer). |

## 3. Request pipeline

Global (`config/web/di/application.php`): `ErrorCatcher → SecurityHeaders → CorrelationId → Tracing (OTel) → Metrics → RequestCatcher → Router`.

Per route group (`config/routes/*.php`):

| Group | Middleware |
|---|---|
| auth (`/login`, `/auth/*`) | Session → CSRF → Locale → AuthContext (session registry, gates) → DomainError(html) |
| web (`/`, `/account`, …) | same + RequireLogin for protected routes |
| admin (`/admin/*`) | Session → CSRF → Locale → AuthContext → RequireLogin → AdminNetwork (IP allowlist setting) → DomainError → `RequirePermission(admin.access)` → per-route `RequirePermission(…)` / `RequireRecentAuth` |
| api (`/api/v1/*`) | feature flag → JSON errors → body parser → CORS → rate limit → Bearer auth → scope/permission (stateless: no session, no cookies, no CSRF) |
| system (`/health/*`, `/metrics`) | none (metrics/health details require `METRICS_TOKEN`) |

## 4. Data

PostgreSQL 17 is the system of record. Key design decisions:

- UUID primary keys (`gen_random_uuid()` defaults; application generates UUIDv7 for index locality).
- Optimistic locking with `version` columns on mutable aggregates; `ConflictException` (409) on stale writes.
- `audit_log` is range-partitioned by month (`platform_ensure_monthly_partitions`), append-only (trigger), HMAC-signed rows.
- Read/write splitting through `DatabaseRouter` (replicas lazily connected, stickiness after writes, automatic fallback).
- Statement/lock/idle-in-transaction timeouts are set per connection (libpq `options`).
- Migrations are plain SQL via `SqlScript::run()` inside `yiisoft/db-migration` (transactional).

Redis is **never** the source of truth. Outage policy: cache fail-open (`FailSafeCache`), sessions fail-closed
(`RedisSessionHandler` → 503), rate limiting fail-open with durable login throttling in PostgreSQL, scheduler leadership
via PostgreSQL advisory locks (not Redis).

## 5. Asynchronous processing

| Mechanism | Use for | Guarantees |
|---|---|---|
| **Job queue** (`JobQueue`, table `jobs`, `FOR UPDATE SKIP LOCKED`) | commands: send e-mail, generate report, backup | enqueue is transactional with business data; at-least-once; retries with exponential backoff + jitter; dead state; idempotency keys; cancel; pause per queue; timeout via SIGALRM; reaper for crashed workers |
| **Scheduler** (`schedules`) | cron jobs | leader election (advisory lock) + `UNIQUE(schedule_id, scheduled_for)` ⇒ exactly once per tick; overlap skip |
| **Transactional outbox → Kafka** | domain events for other modules/services | event persisted atomically with state; relay publishes with idempotent producer (acks=all); per-aggregate ordering via partition key; Kafka down ⇒ events wait, never dropped |
| **Kafka consumers** | reacting to events | manual commit after processing; idempotent via `inbox`; retry topics `platform.retry.1m/10m`; DLQ `platform.dlq` + `kafka_dead_letters` (replay from admin); pause/resume; lag metrics |

Why the queue is not `yiisoft/queue`: it has no stable release yet (dev-master only). The PostgreSQL queue adds
transactional enqueue; it can be swapped behind `JobQueue` if needed.

## 6. Configuration

- **Environment variables** (12-factor) → `config/common/params.php` (`platform.*`).
- **Typed settings** (`config/common/settings.php`, stored in PostgreSQL, versioned, rollback, scopes global/tenant/user,
  env override, secrets encrypted) → `SettingsService`.
- **Feature flags** (`feature_flags`) with lifecycle status, dependencies, rollout %, tenant targeting, schedules;
  critical security controls cannot be switched off.
- Cluster-wide invalidation: `ConfigRevision` (PostgreSQL counter mirrored to Redis; APCu ≤ 2 s per process).

## 7. Security architecture

See [SECURITY.md](SECURITY.md). Summary: Argon2id, MFA (TOTP + recovery codes, replay-safe), server-side session
registry with idle/absolute timeouts and remote revocation, step-up re-authentication for critical operations,
default-deny RBAC (yiisoft/rbac), tenant isolation in application services, strict CSP, CSRF, SSRF guard, encrypted
secrets (XChaCha20-Poly1305), redaction of PII/secrets in logs/audit, tamper-evident audit log.

## 8. Observability

JSON logs to stderr with correlation id / user / tenant; Prometheus metrics (`/metrics`, Redis-aggregated);
OpenTelemetry traces (OTLP, W3C trace context); health endpoints (`/health/live`, `/health/ready`). See [MONITORING.md](MONITORING.md).

## 9. Official packages used (reuse first)

yiisoft/app (structure), config, di, router(-fastroute), middleware-dispatcher, yii-http, runner-http/console,
error-handler, view, yii-view-renderer, assets, html, session, csrf, user, auth, rbac, **rbac-db**, db, **db-pgsql**,
**db-migration**, cache (+cache-redis), validator, hydrator, input-http, translator (+message-php), mailer(-symfony),
data, data-response, request-body-parser, security, aliases, yii-console, yii-gii (dev).

Third-party: guzzlehttp/guzzle, predis/predis, firebase/php-jwt (JWKS), spomky-labs/otphp (TOTP), bacon/bacon-qr-code,
league/flysystem + aws-s3-v3, promphp/prometheus_client_php, open-telemetry/sdk + exporter-otlp, zircote/swagger-php,
dragonmantank/cron-expression, minishlink/web-push, symfony/mailer.

### Custom implementations and why

| Component | Reason |
|---|---|
| PostgreSQL job queue & scheduler | `yiisoft/queue` has no stable release; transactional enqueue required |
| Redis rate limiter (Lua) | `yiisoft/rate-limiter` stores counters via non-atomic PSR-16 get/set |
| Session registry | yiisoft/session has no server-side session inventory/revocation |
| OIDC client | existing PHP OIDC clients couple to `$_SESSION`/globals; PSR-7/DI-friendly implementation with strict validation is ~300 lines on top of firebase/php-jwt |
| OneID adapter | no maintained PHP package; protocol is custom OAuth2 grant types |
| Outbox / Kafka runner | no Yii3 package exists; built on ext-rdkafka |
