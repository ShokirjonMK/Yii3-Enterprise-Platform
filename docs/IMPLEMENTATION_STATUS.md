# Implementation status

Status vocabulary: `IMPLEMENTED` · `TESTED` · `VERIFIED` · `BLOCKED` · `NOT IMPLEMENTED` · `NOT RUN` (see AGENTS.md §6).
This file is the hand-over log: a new session continues from the "Next steps" list at the bottom.

_Last update: 2026-11-04 (session 1)_

## Foundation (lead)

| Area | Status | Evidence |
|---|---|---|
| Docker dev stack (nginx, php-fpm 8.3, worker, scheduler, relay, consumer, PG17, Redis 7.4, Kafka 3.9 KRaft, SeaweedFS S3, Mailpit, monitoring profile) | VERIFIED | `docker compose ps` all healthy |
| PHP image (pdo_pgsql, redis, rdkafka, intl, sodium, apcu, pcov, pg_dump 17) | VERIFIED | `docker run yep-php:dev pg_dump --version` |
| Composer deps on PHP 8.3 platform, `composer.lock` | VERIFIED | `config.platform.php=8.3.36` |
| Migrations (identity, config/SSO, partitioned append-only audit, outbox/queue/scheduler, integrations/notifications, workflow/ops) | VERIFIED | `./yii migrate:up` on dev + test DBs |
| `./yii platform:install` (env checks, DB, migrations, RBAC sync, seed, one-time superadmin, health) | VERIFIED | manual run, idempotent re-run skips bootstrap |
| RBAC catalog (53 permissions, 8 roles) on yiisoft/rbac + rbac-db | TESTED | `AccessSettingsAuditTest` |
| Local login, Argon2id, throttling, lockout, MFA (TOTP + recovery, replay protection), forced password change & MFA enrolment gates, session registry, step-up re-auth | TESTED | `tests/E2E/AuthFlowTest` (7), `AccessSettingsAuditTest::testMfaReplayProtection` |
| OIDC SSO (discovery, PKCE S256, state, nonce, JWKS sig, iss/aud/azp/exp) | TESTED (mock IdP) | `tests/Unit/OidcIdTokenValidationTest` |
| OneID adapter | IMPLEMENTED · UNVERIFIED | needs operator-issued client_id/secret/scope + endpoint |
| Settings (typed, env override, scopes, versions, rollback, encrypted secrets, cluster revision invalidation) | TESTED | `AccessSettingsAuditTest` |
| Feature flags (7 statuses, critical guard, dependency validation, rollout %, tenant override, schedule) | TESTED | `AccessSettingsAuditTest` |
| Audit log (HMAC-signed, immutable trigger, monthly partitions, verifier) | TESTED | tamper test incl. trigger bypass |
| Job queue (SKIP LOCKED, idempotency, backoff, dead, retry, cancel, pause, reaper) + worker (signals, timeout, recycle) | TESTED | `QueueAndSchedulerTest` |
| Scheduler (leader lock + unique tick → exactly once, overlap skip, cron preview) | TESTED | `QueueAndSchedulerTest` |
| Transactional outbox → relay → Kafka → consumer group with inbox idempotency; Kafka-down keeps events | TESTED (real broker) | `OutboxKafkaRoundTripTest` |
| Retry topics / DLQ / replay | IMPLEMENTED | admin UI + tests pending (runtime agent) |
| Notifications core (in-app, e-mail via SMTP, queued delivery, skip tracking) | TESTED (Mailpit) | `NotificationDeliveryTest` |
| Security headers / CSP / correlation id / JSON logs with redaction | TESTED | `AuthFlowTest::testLoginPageRendersWithSecurityHeaders`, `SecurityPrimitivesTest` |
| Admin shell (24-section navigation, HTMX partial navigation, light/dark, responsive), dashboard with live KPIs & health | TESTED | `AuthFlowTest::testAdminWithMfaFullFlow` |
| Prometheus metrics endpoint, health live/ready | IMPLEMENTED | — |

## Work packages in progress (parallel agents)
See sections below as they are completed.

## Next steps
1. Admin sections 02–24 (agents: identity, runtime, ops, integration/workflow).
2. REST API v1 + OpenAPI + Projects sample module.
3. Enterprise Gii generators (after Projects module).
4. Kubernetes/HA, GitLab CI, observability configs, docs.
5. Translations uz/ru, final security & performance audit, final report.
