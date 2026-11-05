# Administrator guide — Platform Control Center (`/admin`)

Access requires `admin.access`; each section and every action has its own permission (see [RBAC.md](RBAC.md)).
Critical actions (role grants, SSO secrets, backups, deployments, replay, cache clear, …) ask for **password + MFA
re-confirmation** (valid 5 minutes). Every change is recorded in the audit log.

First steps after `./yii platform:install`:
1. Sign in as the bootstrap superadmin, change the temporary password, enrol MFA (mandatory), save recovery codes.
2. **03 Authentication** — configure OneID / corporate OIDC; keep at least one superadmin with policy `local_only` (break-glass).
3. **02 Users** — create administrators; grant roles in **04 RBAC** (only a superadmin can grant admin roles).
4. **21 System Settings** — support e-mail, retention, admin IP allowlist, notification providers.
5. **19 Backup** — run a backup and a restore verification; enable the nightly schedules in **10 Jobs & Scheduler**.
6. Optional demo data in non-production: `./scripts/platform.sh demo`.

## Optional features — switch on only what the project uses

Every optional part is a feature flag (registry: `config/common/features.php`), toggled in **21 System Settings → Feature flags**
(per tenant/user rollout possible). A disabled section disappears from the menu; opening its URL shows "This section is switched off" with an **Enable this section** button (settings.manage), all other requests return 404; a disabled subsystem
does no work at all (no queries, no network). Changes reach all processes within ~2 s.

| Flag | Default | Effect when OFF |
|---|---|---|
| `feature.integrations`, `feature.webhooks`, `feature.workflow`, `feature.infrastructure`, `feature.deployments` | OFF | section 07 / inbound webhooks / 09 / 15 / 16 hidden, 404 |
| `messaging.kafka` | OFF | outbox relay and consumers pause; section 11 hidden |
| `feature.multitenancy`, `feature.api_management`, `feature.notifications`, `feature.database_admin`, `feature.redis_admin`, `feature.storage`, `feature.monitoring`, `feature.backups`, `feature.incidents`, `feature.business_modules`, `module.<code>` | ON | the matching section/module hidden, 404 |
| `api.v1`, `api.docs` | ON | REST API / OpenAPI + Swagger UI return 404 |
| `audit.user_activity` | ON | no rows written to `user_activity` |
| `logging.database` | ON | logs only to stderr (JSON), not to `app_logs` |
| `observability.metrics` / `observability.tracing` | ON / OFF | `/metrics` 404 and no counters / no OpenTelemetry spans |

Core sections (dashboard, users, auth, RBAC, jobs, security, audit, settings) cannot be switched off.

**Logs and user actions:** *20 Audit* — tamper-evident audit log of every change (who/what/before/after) plus **User activity**
(every page and API call: user, route, status, duration, IP, correlation id; filters, 24 h KPIs). *17 Monitoring → Application logs* —
warnings/errors of web, API, workers and consumers with context (secrets masked). Retention: `retention.activity_days` (90),
`retention.app_logs_days` (30), monthly partitions created automatically.

**08 Notifications → Settings** (per channel): SMTP DSN + sender, Telegram bot token + alert chat id, SMS gateway URL/token/sender,
Web Push VAPID keys (or *Generate key pair*). Secrets are encrypted, never displayed again, saving requires re-authentication;
an environment variable with the same purpose overrides the field and is marked "set by environment".

| # | Section | What you can do | Permissions (view / manage) |
|---|---|---|---|
| 01 | Dashboard | live KPIs (auto refresh), dependency health, recent activity, sign-in chart, global search | admin.access |
| 02 | Users & Organizations | users CRUD, block/unblock, archive, unlock, one-time password reset, roles, sessions, SSO links; organization tree | users.view / users.manage |
| 03 | Authentication | local/SSO switches, OneID/OIDC/OAuth2 providers: configure, test, enable/disable, health, history | auth.view / auth.manage |
| 04 | RBAC & Access Control | roles, effective permissions, matrix, assignments, catalog sync | rbac.view / rbac.manage |
| 05 | Multi-Tenancy | tenants CRUD, suspend/archive (signs users out), per-tenant feature flags | tenants.view / tenants.manage |
| 06 | API Management | API clients (secret shown once), rotation, IP allowlists, rate limits, personal tokens | api.view / api.manage |
| 07 | Integrations | outbound connectors (timeouts, retries, circuit breaker, auth), call log, inbound webhooks | integrations.view / .manage |
| 08 | Messaging & Notifications | channels (e-mail, Telegram, SMS, Web Push, in-app), templates uz/ru/en, deliveries, broadcast | notifications.view / .manage / .send |
| 09 | Workflow & Approvals | workflow definitions (JSON, validated), instances, approvals inbox (four-eyes) | workflow.view / workflow.manage / approvals.decide |
| 10 | Jobs & Scheduler | queues (pause), jobs (retry/cancel), workers, schedules (cron preview, run now) | jobs.view / jobs.manage |
| 11 | Kafka Management | brokers, topics vs registry, consumer lag, pause/resume, outbox, dead letters (replay), handlers (#[KafkaChannel]), publish JSON, settings (connection, SASL/SSL, producer, consumer, retry/failure policy) | kafka.view / kafka.manage |
| 12 | PostgreSQL Management | activity, slow queries, sizes, locks, replication, replicas, ANALYZE/VACUUM, cancel query | db.view / db.manage |
| 13 | Redis Management | INFO, memory, slowlog, key browser (no values), namespace cache clear | redis.view / redis.manage |
| 14 | Storage & Search | S3 health, files (validated upload, authorized download), search diagnostics | storage.view / storage.manage |
| 15 | Infrastructure | runtime inventory, PHP/opcache, Kubernetes (deployments/pods/nodes, scale) | infra.view / infra.manage |
| 16 | CI/CD & Deployments | request → four-eyes approval → GitLab pipeline; rollback | deploy.view / deploy.request / deploy.approve |
| 17 | Monitoring & Observability | RPS, errors, p95/p99, queues, lag (Prometheus or built-in metrics), alerts | monitoring.view |
| 18 | Security Center | posture checks, security events, lockouts, all sessions/tokens revocation, ASVS checklist | security.view / security.manage |
| 19 | Backup & Recovery | backups to S3 with checksum, restore verification, retention, WAL/PITR status | backup.view / backup.manage |
| 20 | Audit & Compliance | search, diff, redacted CSV export, HMAC integrity verification, partitions | audit.view / audit.export |
| 21 | System Settings | typed settings with source/version/rollback; feature flags (rollout, tenants, schedule) | settings.view / settings.manage |
| 22 | Developer Tools | (non-production) routes, jobs, consumer groups, environment, migrations, **Enterprise Gii** | devtools.use |
| 23 | Business Modules | module registry, enable/disable (module flags); Projects and Contracts samples | modules.view / modules.manage |
| 24 | Incident Management | incidents sev1–4: acknowledge, timeline, commander, postmortem; auto-open from health probes | incidents.view / incidents.manage |

"Not configured" means the external system (Kubernetes API, GitLab, Prometheus, Telegram, SMS gateway, IdP) has no
credentials yet — configure it in **21 System Settings** or the section itself; nothing is simulated.

— Author: ShokirjonMK

## Login history

- **Every user** — Account → *Login history* (`/account/login-history`, also linked from *Security & sessions*): own
  successful sign-ins and failed attempts on the account (wrong password, wrong 2FA code, locked, …) with time, IP,
  device, method (password / SSO / API) and the second factor used. Unknown entries → change the password and end
  the other sessions.
- **Administrators** — Admin → 02 Users & Organizations → *Login history* (`/admin/users/login-history`, also the
  *Login history* button on a user): every attempt, filters by user, IP prefix, result, method / second factor and
  period. Permission `users.login_history` (role `auditor`, inherited by `security_admin`, `devops_sre`,
  `platform_admin`; `superadmin` always). Without `tenants.cross` only the own tenant is shown.
- Retention: System Settings → Retention → *Login history retention (days)* (default 90).


## Sessions & sign-in limits

Admin → 03 Authentication → *Sessions & sign-in* (`/admin/auth/sessions`, permission `auth.manage`, step-up to save;
every change is versioned and audited). Applied at runtime by `SecurityPolicy` — the next request uses the new value,
no restart:

| Setting | Default | Effect |
|---|---|---|
| Sign out after inactivity (minutes) | 30 | idle session timeout (CurrentUser + session registry) |
| Maximum session length (hours) | 12 | absolute session lifetime (also the Redis session TTL) |
| Step-up confirmation valid for (minutes) | 5 | how long a password + 2FA confirmation covers critical actions |
| Failed logins before lockout / lockout (minutes) | 5 / 15 | per-account lockout |
| Login history retention (days) | 90 | Maintenance cleanup of `login_attempts` |

## Why can't a user switch 2FA off?

2FA is mandatory when **the user's own flag** "Require two-factor authentication" is set (Admin → Users → edit; the
installer sets it for the first superadmin) **or** a **role policy** requires it (Admin → Authentication → Two-factor
→ Policies by role; seeded: superadmin, platform_admin, security_admin, devops_sre; the general `admin` role has no policy and follows `*`, i.e. optional). Account → Security shows which one
applies, with links for administrators. To make 2FA optional for a superadmin: untick the flag on the user and set the
superadmin policy to *not required* (only a superadmin can edit policies of administrative roles; audited).
