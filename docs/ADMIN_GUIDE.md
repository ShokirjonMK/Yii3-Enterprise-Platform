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
| 11 | Kafka Management | brokers, topics vs registry, consumer lag, pause/resume, outbox, dead letters (replay) | kafka.view / kafka.manage |
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
