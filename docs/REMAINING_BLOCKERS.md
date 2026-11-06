# Remaining blockers and unverified items

Everything below is implemented in code (real adapters, health checks, "Not configured" states) but could **not** be
verified end-to-end in this environment. Nothing here is reported as PASS.

| Item | Status | What is needed to verify |
|---|---|---|
| OneID (id.egov.uz) login | UNVERIFIED | operator-issued endpoint URL, client_id, client_secret, scope; callback `https://<host>/auth/sso/<code>/callback` registered with the operator |
| Corporate OIDC against a real IdP (Keycloak/ADFS/Entra) | UNVERIFIED (mock IdP tests pass) | issuer URL, client id/secret, redirect URI registration |
| Kubernetes manifests (CloudNativePG, Strimzi, Redis Sentinel, KEDA, ExternalSecrets, NetworkPolicies, Ingress) | VALIDATED (kustomize + kubeconform 59/59), NOT APPLIED | a cluster with the operators installed; `scripts/ci/deploy.sh staging` |
| GitLab CI pipeline / GitHub Actions | NOT RUN on a runner. Every step of `.github/workflows/ci.yml` passes locally (composer validate, php -l, php-cs-fixer, Psalm with baseline, unit, composer audit). GitHub has registered no workflow for the repository, so Actions are disabled there | enable Actions in the GitHub repository settings (Settings → Actions → General); push to GitLab with protected variables (docs/CI_CD.md) |
| GitLab deployment trigger, Kubernetes scaling from admin | UNVERIFIED (mocked API tests) | `ops.gitlab_*`, `ops.kubernetes_*` settings |
| Prometheus / Alertmanager in production | VERIFIED locally (monitoring profile, 9/9 targets UP); production UNVERIFIED | ServiceMonitor + cluster |
| Telegram, SMS gateway, Web Push delivery | UNVERIFIED (mocked provider tests) | bot token + chat id, SMS gateway URL/token, VAPID keys + browser service worker |
| PITR restore in production (barman / WAL) | UNVERIFIED | CNPG cluster with object storage; runbook docs/runbooks/pitr-recovery.md |
| Redis Cluster mode in admin Redis section | UNVERIFIED (standalone tested; Sentinel failover simulated locally) | Redis Cluster |
| PostgreSQL streaming replicas / read routing | IMPLEMENTED, tested only with zero replicas | `DB_REPLICAS` pointing to standbys |
| Terraform `plan/apply`, Ansible against hosts | `validate` / `syntax-check` + lint pass; NOT APPLIED | cloud credentials / hosts |
| Load testing on production hardware | baseline on dev laptop only (see IMPLEMENTATION_STATUS) | staging environment |
| Commit signing (GitHub "Verified") | NOT CONFIGURED | register an SSH/GPG signing key on the GitHub account |

— Author: ShokirjonMK
