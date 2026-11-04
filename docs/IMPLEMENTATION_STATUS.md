# Implementation status

Status vocabulary: `IMPLEMENTED` · `TESTED` · `VERIFIED` · `BLOCKED` · `NOT IMPLEMENTED` · `NOT RUN` (AGENTS.md §6).
Unverified external integrations are listed in [REMAINING_BLOCKERS.md](REMAINING_BLOCKERS.md).

_Last update: 2026-11-04 — Author: ShokirjonMK_

## Test results (last full run, local Docker stack)

| Suite | Result |
|---|---|
| unit + integration (`APP_ENV=test`, real PostgreSQL/Redis/Kafka/SMTP/S3) | **228 tests OK**, 1260 assertions |
| api + security (HTTP against nginx) | **73 tests OK**, 768 assertions (+ Contracts API 4 OK) |
| e2e (HTML flows, HTMX, MFA, all admin sections) | **83 tests OK**, 689 assertions |
| live smoke `scripts/smoke-admin.php` (superadmin, 24 sections + modules + API) | **31/31 OK** |
| infra validation | kustomize+kubeconform 59/59 per overlay, promtool, amtool, otelcol, terraform validate, ansible-lint — OK |
| load (k6, dev laptop, bind-mounted dev image) | 0 % errors; latency not representative (Windows bind mount) — production-image load test NOT RUN |

## Platform Control Center — 24 sections

| # | Section | Status |
|---|---|---|
| 01 | Dashboard | VERIFIED |
| 02 | Users & Organizations | TESTED · VERIFIED |
| 03 | Authentication (local, OIDC, OneID, OAuth2) | TESTED (mock IdP) · OneID/real IdP UNVERIFIED |
| 04 | RBAC & Access Control | TESTED · VERIFIED |
| 05 | Multi-Tenancy | TESTED · VERIFIED |
| 06 | API Management | TESTED · VERIFIED |
| 07 | Integrations (+ webhooks, SSRF guard, circuit breaker) | TESTED · VERIFIED (webhook over HTTP) |
| 08 | Messaging & Notifications | TESTED (in-app + e-mail via Mailpit VERIFIED; Telegram/SMS/WebPush mocked → UNVERIFIED) |
| 09 | Workflow & Approvals | TESTED · VERIFIED |
| 10 | Jobs & Scheduler | TESTED · VERIFIED |
| 11 | Kafka Management (outbox, lag, DLQ replay) | TESTED · VERIFIED (real broker) |
| 12 | PostgreSQL Management | TESTED · VERIFIED (replicas: none in dev) |
| 13 | Redis Management | TESTED · VERIFIED (standalone) |
| 14 | Storage & Search | TESTED · VERIFIED (S3) |
| 15 | Infrastructure | TESTED · Kubernetes API UNVERIFIED |
| 16 | CI/CD & Deployments | TESTED · GitLab API UNVERIFIED |
| 17 | Monitoring & Observability | TESTED · VERIFIED (built-in metrics; Prometheus profile VERIFIED locally) |
| 18 | Security Center | TESTED · VERIFIED |
| 19 | Backup & Recovery | TESTED · VERIFIED (real pg_dump → S3 → restore verification) |
| 20 | Audit & Compliance | TESTED · VERIFIED (HMAC tamper detection) |
| 21 | System Settings & Feature Flags | TESTED · VERIFIED |
| 22 | Developer Tools + Enterprise Gii | TESTED · VERIFIED (non-prod only) |
| 23 | Business Modules (Projects, Contracts) | TESTED · VERIFIED |
| 24 | Incident Management | TESTED · Telegram alerts UNVERIFIED |

## Platform capabilities

| Area | Status |
|---|---|
| Docker stack (nginx, php-fpm, worker, scheduler, relay, consumer (all groups), PG17, Redis, Kafka KRaft, S3, Mailpit) | VERIFIED |
| `./yii platform:install` (idempotent, one-time superadmin, health) · `./yii platform:demo` | VERIFIED |
| Login, Argon2id, lockout, throttling, MFA, forced password change/MFA enrolment, sessions, step-up | TESTED · VERIFIED |
| RBAC default deny, last-superadmin guard, tenant isolation (IDOR → 404), suspended tenants cannot log in | TESTED |
| REST API v1 (token grants, refresh rotation, scopes, errors, pagination, CORS, rate limits), OpenAPI + Swagger UI | TESTED · Swagger UI visual check NOT RUN |
| Job queue, scheduler exactly-once, transactional outbox → Kafka → idempotent consumers, retry topics, DLQ | TESTED (real broker) |
| Settings (versions, rollback, encrypted secrets), feature flags (rollout, dependencies, critical guard) | TESTED |
| Audit (append-only, HMAC, partitions) | TESTED |
| Observability: JSON logs + redaction, Prometheus metrics, OpenTelemetry tracing, health endpoints | TESTED · VERIFIED locally |
| Optional features ON/OFF from the admin panel (`config/common/features.php`: sections → 404 + hidden menu, API docs, webhooks, Kafka, metrics, tracing) | TESTED (E2E) · VERIFIED (smoke) |
| User activity log (`user_activity`, every authenticated web/API request, written after the response) · Admin → 20 Audit → User activity | TESTED (E2E) |
| Application logs in DB (`app_logs`, warning+ from every process, redacted) · Admin → 17 Monitoring → Application logs | TESTED (E2E) |
| Notification provider credentials (SMTP, Telegram bot, SMS gateway, VAPID) edited in Admin → 08 | TESTED (E2E) · real Telegram/SMS delivery UNVERIFIED |
| Translations uz/ru (1622 strings, ICU-validated) | VERIFIED |
| Production image (non-root, read-only, no dev deps), nginx image | VERIFIED (smoke) |
| Kubernetes HA (CNPG, Redis Sentinel, Strimzi, KEDA, NetworkPolicies), GitLab CI, GitHub Actions | VALIDATED · NOT APPLIED / NOT RUN on a runner |

## Production readiness: GO / NO-GO

**NO-GO for production as-is** until: OneID/IdP credentials are configured and a real login is verified, the
Kubernetes manifests are applied to a staging cluster, the CI pipeline runs green on a runner, and a load test on
the production image passes. **GO for development/staging use** (local stack fully working, all suites green).
