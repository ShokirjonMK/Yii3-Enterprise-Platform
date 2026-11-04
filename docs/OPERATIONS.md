# Operations guide

Routine operations, incident response and on-call for the Yii3 Enterprise Platform. The Platform Control Center
sections referenced here: 15 Infrastructure (`/admin/infrastructure`), 16 CI/CD & Deployments (`/admin/deployments`),
17 Monitoring (`/admin/monitoring`), 18 Security Center (`/admin/security`), 19 Backup & Recovery (`/admin/backups`),
20 Audit & Compliance (`/admin/audit`), 24 Incident Management (`/admin/incidents`).

Commands below use the local dev stack (`docker compose`, Git Bash: prefix `MSYS_NO_PATHCONV=1`) and the production
Kubernetes namespace `platform` (`kubectl -n platform`).

## 1. On-call

- **Who is on call**: every active user holding `incidents.manage` (roles `devops_sre`, `platform_admin`, `superadmin`).
  They receive `incident.opened` notifications (in-app; sev1/sev2 also by e-mail). Telegram: set
  `notifications.telegram_bot_token` + `notifications.telegram_alert_chat` (or `TELEGRAM_BOT_TOKEN` / `TELEGRAM_ALERT_CHAT_ID`)
  and every new/resolved incident is posted to the alert chat.
- **Automatic incidents**: the job `platform.health.probe` (schedule `platform.health`, every 5 min) opens an incident
  with fingerprint `health:<component>` when PostgreSQL/Redis (sev1) or Kafka/S3 storage (sev2) is DOWN for two
  consecutive probes and resolves it when the component is UP again. State: table `health_probe_state`.
- **Acknowledge within**: sev1 5 min, sev2 15 min, sev3 next business hour, sev4 best effort.
  MTTA/MTTR of the last 30 days are shown on `/admin/incidents`.

### Severity

| Severity | Definition | Examples | Communication |
|---|---|---|---|
| sev1 | Platform unavailable or data at risk | DB down, mass login failure, security breach | IC + status updates every 30 min, postmortem required |
| sev2 | Major feature degraded for many users | Kafka down (events queue in outbox), S3 down, p99 > 3 s | IC, updates hourly, postmortem required |
| sev3 | Minor impact / workaround exists | one integration failing, slow reports | ticket-level updates |
| sev4 | Cosmetic, no user impact | dashboard glitch, DR drill record | none |

## 2. Incident response procedure

1. **Open** (`/admin/incidents` → "Open an incident") or take over the automatic one. Pick severity and service.
2. **Acknowledge** — the first responder becomes incident commander (IC) unless one is assigned; status → investigating.
3. **Investigate** — post every finding as an update (timeline). Useful places:
   - `/admin/monitoring` (error rate, p95/p99, queue depth, consumer lag, alerts),
   - `/admin/infrastructure` (worker heartbeats, component health, pods),
   - `/admin/audit?correlation_id=<X-Request-Id>` (everything done in one request),
   - logs: `docker compose logs app --since 10m | grep '"level":"error"'` / `kubectl -n platform logs deploy/app --since=10m`.
4. **identified → monitoring** after mitigation; keep monitoring for at least 15 min (sev1/sev2).
5. **resolved** — service restored. Automatic incidents resolve themselves when the probe is green again.
6. **Postmortem** (blameless; required before closing sev1/sev2): summary, impact, timeline, root cause, what went
   well/badly, action items with owner and due date. Then **closed** (final).

## 3. Component runbooks

| Symptom | Runbook |
|---|---|
| PostgreSQL down / failover / replication lag | `docs/runbooks/postgres-down.md` |
| Restore a logical backup | `docs/runbooks/restore-database.md` |
| Point-in-time recovery | `docs/runbooks/pitr-recovery.md` |
| Queue backlog / workers not running | `docs/runbooks/queue-backlog.md` |
| Audit integrity failure | `docs/runbooks/audit-integrity.md` |
| Backup/DR design, RPO/RTO | `docs/BACKUP_RECOVERY.md` |

## 4. Routine operations

### Daily
- Dashboard: health all green, failed jobs (24 h), dead letters, open incidents.
- `/admin/backups`: last successful backup < 26 h, WAL archiver without new failures.
- `/admin/security`: failed sign-ins by IP (brute force?), locked accounts, privileged users without MFA.

### Weekly
- Restore test passed (`platform.backup.verify` schedule, Sunday 04:00) — investigate any `failed`.
- Audit integrity: `/admin/audit/integrity` → "Verify signatures" for the last 7 days (expect 0 invalid).
- Review `/admin/audit?category=security&result=denied` for unexpected denials.

### Monthly
- Review active sessions and API tokens of leavers (`/admin/security/sessions` → revoke all / revoke API tokens).
- Review role assignments (`/admin/rbac`), privileged accounts, break-glass account usage (`auth.*` audit of local_only users).
- Rotate service tokens that expire (GitLab, Kubernetes service account, SMTP, Telegram) via System Settings → Operations.

### Quarterly
- DR drill (`docs/BACKUP_RECOVERY.md` §7) — record as a sev4 incident with postmortem.
- Update `docs/SECURITY_ASVS.md` statuses.

## 5. Deployments (four-eyes)

1. Requester (`deploy.request`) opens `/admin/deployments`, chooses environment, version (release/image tag), strategy.
2. A **different** user with `deploy.approve` reviews and approves (step-up password + TOTP). The requester cannot
   approve or reject their own request (service check + DB constraint `deployments_four_eyes`; denials are audited).
3. Approval triggers a GitLab pipeline (`POST /api/v4/projects/:id/pipeline`, ref `GITLAB_DEPLOY_REF` (default `main`),
   variables `DEPLOY_ENV`, `VERSION`, `DEPLOY_STRATEGY`, `DEPLOYMENT_ID`). The job `platform.deploy.poll` polls every
   30 s and records `succeeded`/`failed`; "Refresh status" polls immediately.
4. Rollback: on a succeeded deployment "Request rollback" creates a new request for the previous successful version in
   the same environment (same approval). When it succeeds, the original deployment is marked `rolled_back`.
5. GitLab not configured → the approved deployment stays `approved`; "Trigger pipeline" retries after configuration.

Configure: `ops.gitlab_url`, `ops.gitlab_project_id`, `ops.gitlab_token` (project access token, scope `api`, role
Developer+ on the deploy project). The `.gitlab-ci.yml` deploy job must read `DEPLOY_ENV`/`VERSION` and only run when
`$CI_PIPELINE_SOURCE == "api"`.

## 6. Scaling (Kubernetes)

`/admin/infrastructure` → Kubernetes → Deployments → replicas + reason → Apply (requires `infra.manage` + step-up;
audited as `infra.k8s.scale` with before/after). Equivalent CLI: `kubectl -n platform scale deploy/worker --replicas=4`.
Remember the HPA overrides manual replica counts within its min/max — change the HPA for lasting changes.

Kubernetes settings: `ops.kubernetes_api` (e.g. `https://kubernetes.default.svc`), `ops.kubernetes_namespace`,
`ops.kubernetes_token` (service-account token). Minimal RBAC:

```yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata: { name: platform-ops, namespace: platform }
rules:
  - apiGroups: ["apps"]
    resources: ["deployments"]
    verbs: ["get", "list"]
  - apiGroups: ["apps"]
    resources: ["deployments/scale"]
    verbs: ["get", "patch"]
  - apiGroups: [""]
    resources: ["pods"]
    verbs: ["get", "list"]
  - apiGroups: ["autoscaling"]
    resources: ["horizontalpodautoscalers"]
    verbs: ["get", "list"]
```
(Optional ClusterRole with `nodes: list` for the node table.) Inside the cluster the CA at
`/var/run/secrets/kubernetes.io/serviceaccount/ca.crt` is used automatically; elsewhere set `K8S_CA_FILE`.

## 7. Security operations

- **Locked account**: Security Center → "Failed sign-ins by account" / "Locked accounts" → Unlock (`security.manage`).
  Check first in the audit log whether the failures came from the user or from an attacker IP.
- **Compromised account**: terminate all sessions (`sessions.revoke`, step-up), revoke all API tokens
  (`security.manage`, step-up), reset the password (Users section), review `/admin/audit?actor=<user id>`.
- **Brute force from one IP**: failures by IP table; block at the ingress/WAF; consider `security.allowed_admin_networks`.
- **Audit integrity failure**: `docs/runbooks/audit-integrity.md` (treat as sev1 security incident).

## 8. Monitoring

- With the monitoring profile (`docker compose --profile monitoring up -d`) the Monitoring section queries Prometheus
  (`PROMETHEUS_URL`) and Alertmanager (`ALERTMANAGER_URL`, default `http://alertmanager:9093`); links to Grafana/Jaeger
  appear when `GRAFANA_URL` / `JAEGER_URL` are set.
- Without Prometheus the section shows the in-app registry (Redis-backed counters/histograms, cumulative since the
  last metrics reset) and live database gauges — the active source is always labelled on the page.

## 9. Useful commands

```bash
docker compose ps                                                     # stack status
docker compose logs app --since 5m | grep '"level":"error"'           # recent errors
docker compose exec app php yii migrate:new                           # pending migrations
docker compose exec -T -e APP_ENV=test app php vendor/bin/phpunit --testsuite unit,integration --filter Ops
docker compose exec app sh -c 'PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -U $DB_USER -d $DB_NAME -c "select * from pg_stat_archiver"'
kubectl -n platform get deploy,pods,hpa
kubectl cnpg status platform-pg -n platform
```

— Author: ShokirjonMK
