# CI/CD (GitLab)

Pipeline definition: [`.gitlab-ci.yml`](../.gitlab-ci.yml). Helper scripts: `scripts/ci/`.
Kubernetes manifests: `infra/kubernetes/overlays/{staging,production}` (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## Pipeline flow

```
validate ─► build-deps ─► quality ─► test-unit ─► test-integration ─► test-api ─► security
   │                                                                               │
   └──────────────────────────────── build (php + nginx images) ◄──────────────────┘
                                          │
                       scan (trivy image, syft SBOM, cosign*) 
                                          │
             deploy-staging ─► smoke-staging (+ ZAP baseline, manual)
                                          │
             approve-production (manual, protected env) ─► deploy-production ─► verify-production
                                          │
                                 rollback (manual, staging/production)
```

`*` cosign signing only when `COSIGN_ENABLED=true`.

| Stage | Jobs | What happens |
|---|---|---|
| validate | `validate:yaml`, `validate:composer`, `validate:kubernetes`, `validate:observability`, `validate:alertmanager`, `validate:shell` | YAML parse of CI/compose/infra/observability files (GitLab `!reference` tolerated), `composer validate --strict`, `scripts/ci/k8s-validate.sh staging production` (kustomize build + kubeconform), `promtool check config/rules`, `amtool check-config`, shellcheck. |
| build-deps | `ci-image`, `composer-install` | Builds the `dev` target of `infra/docker/php/Dockerfile` (same extensions as prod + composer/pcov) and pushes it as `$CI_REGISTRY_IMAGE/ci:<ref-slug>` with a registry build cache; `composer install` with a cache keyed on `composer.lock`; `vendor/` passed on as an artifact. |
| quality | `lint:php`, `cs`, `psalm`, `dependency-analyser`, `composer-audit` | `php -l` on all sources, php-cs-fixer dry-run, Psalm (Code Quality report), composer-dependency-analyser, `composer audit --locked`. |
| test-unit | `test:unit` | `phpunit --testsuite unit` (JUnit report). |
| test-integration | `test:integration` | Services `postgres:17-alpine`, `redis:7.4-alpine`, `apache/kafka:3.9.1` (single-node KRaft). `APP_ENV=test`: `tests/bootstrap.php` runs `migrate:up`, `rbac:sync`, `platform:seed` against `DB_TEST_NAME=platform_test`. Coverage via pcov (Cobertura). |
| test-api | `test:api`, `test:e2e`, `test:security` | Same services; `scripts/ci/start-test-app.sh` migrates/seeds the job database and starts the app with `php -S` (`public/index.php`, the same `HttpApplicationRunner` as php-fpm) on `127.0.0.1:8080`; suites run with `E2E_BASE_URL=http://127.0.0.1:8080`. `APP_ENV=dev` because the built-in server is plain HTTP and `prod` enforces Secure `__Host-` cookies. Suites whose directory does not exist yet are skipped with a message. |
| security | `secrets:gitleaks`, `trivy:fs`, `sast:semgrep` | gitleaks over full git history (redacted SARIF), Trivy filesystem scan (vuln + secret + misconfig, HIGH/CRITICAL, fails the job) + CycloneDX source SBOM, Semgrep `p/php` + `p/secrets`. GitLab's own `Security/SAST.gitlab-ci.yml` template can be added on Ultimate; Semgrep is used so the pipeline works on every tier. |
| build | `build:images` | `docker buildx` (dind): prod PHP image (`--target prod`) and the nginx image (`--build-arg PHP_IMAGE=<pushed php image>`, so public assets are byte-identical), BuildKit provenance + SBOM attestations, registry layer cache, tags `$CI_COMMIT_SHORT_SHA` (+ git tag on tag pipelines). Digests stored as artifacts. Manual on merge requests. |
| scan | `trivy:image`, `sbom:syft`, `sign:cosign` | Trivy image scan (fails on fixable CRITICAL, GitLab container-scanning report for HIGH), Syft CycloneDX/SPDX SBOMs (kept 1 year), optional keyless cosign signing by digest (GitLab OIDC id_token). |
| deploy-staging | `deploy:staging` | `resource_group: staging` (no concurrent deploys). `scripts/ci/deploy.sh staging <ns> <tag>`: `kustomize edit set image`, server-side dry-run, delete old `platform-migrate` Job, apply only the migration Job and wait (fail ⇒ abort, old pods keep serving), apply everything else, `kubectl rollout status` for web/worker/scheduler/relay/consumers. Runs on default branch, `release/*` and tags. |
| smoke-staging | `smoke:staging`, `dast:zap-baseline` | `scripts/ci/smoke.sh $STAGING_URL`: `/health/live`, `/health/ready` (body status up/degraded), `/login` 200 + CSP/XFO/nosniff/HSTS, no version disclosure, API health (`SMOKE_API_PATH`, 404 = warning unless `SMOKE_REQUIRE_API=1`), `/metrics` rejected without token. ZAP baseline is manual and `allow_failure`. |
| approve-production | `approve:production` | Manual gate (`allow_failure: false` blocks the pipeline) on environment `production` (`action: prepare`). Protect the environment so only release managers can run it. Default branch or tags only. |
| deploy-production | `deploy:production` | `resource_group: production`; same `deploy.sh` flow against `KUBECONFIG_PRODUCTION`: migration Job first, then rollout and `rollout status`. |
| verify-production | `verify:production` | Smoke test against `$PRODUCTION_URL`. |
| rollback | `rollback:production`, `rollback:staging` | Manual. `scripts/ci/rollback.sh <ns> [revision]` → `kubectl rollout undo` for every Deployment + `rollout status`, then a smoke test. Set `ROLLBACK_REVISION` to target a specific revision. |

### Rollback rules
- Application rollback = previous ReplicaSet (`kubectl rollout undo`). Images are immutable and tagged by commit, so
  re-running `deploy:*` from an older pipeline is an equivalent, auditable rollback.
- Migrations are **not** reverted automatically. Migrations follow expand/contract (see DEPLOYMENT.md): release N
  only adds compatible schema; destructive "contract" steps ship in a later release, so N-1 still runs on the
  expanded schema. Data recovery uses CloudNativePG point-in-time recovery (runbook in DEPLOYMENT.md).

## Required CI/CD variables

Set in *Settings → CI/CD → Variables*. **Never commit values.** "Protected" = only available on protected
branches/tags; "Masked" = hidden in job logs.

| Variable | Type | Protected | Masked | Used by | Purpose |
|---|---|---|---|---|---|
| `KUBECONFIG_STAGING` | File | yes (or scoped to env `staging`) | — | deploy/rollback staging | kubeconfig of a namespace-scoped deploy ServiceAccount |
| `KUBECONFIG_PRODUCTION` | File | yes, env scope `production` | — | deploy/rollback/verify production | kubeconfig of the production deploy ServiceAccount |
| `STAGING_URL` | Variable | no | no | deploy:staging, smoke, ZAP | e.g. `https://staging.platform.example` |
| `PRODUCTION_URL` | Variable | yes | no | production jobs | public base URL |
| `STAGING_NAMESPACE` | Variable | no | no | staging jobs | default `platform-staging` |
| `PRODUCTION_NAMESPACE` | Variable | yes | no | production jobs | default `platform-prod` |
| `METRICS_TOKEN` | Variable | yes | yes | smoke.sh (optional) | overrides the CI-only dummy; enables the authenticated `/metrics` check |
| `COSIGN_ENABLED` | Variable | no | no | sign:cosign | `true` to sign images (keyless, GitLab OIDC) |
| `ROLLBACK_REVISION` | Variable (set when running the manual job) | — | — | rollback jobs | optional target revision |
| `SMOKE_REQUIRE_API` | Variable | no | no | smoke.sh | `1` once `/api/v1/health` exists |

Predefined GitLab variables used: `CI_REGISTRY`, `CI_REGISTRY_IMAGE`, `CI_REGISTRY_USER`, `CI_REGISTRY_PASSWORD`,
`CI_COMMIT_SHORT_SHA`, `CI_COMMIT_TAG`, `CI_DEFAULT_BRANCH`, `CI_COMMIT_REF_SLUG`.

Application secrets (`APP_SECRET`, `DB_PASSWORD`, `REDIS_PASSWORD`, `S3_SECRET`, `KAFKA_SASL_PASSWORD`,
`METRICS_TOKEN`, `TELEGRAM_BOT_TOKEN`, OIDC client secrets, …) are **not** CI variables: they live in the external
secret manager and reach pods through `ExternalSecret` (see DEPLOYMENT.md). CI only holds deploy credentials.
The non-secret values in the `variables:` block of `.gitlab-ci.yml` (`ci-only-*`) configure throw-away service
containers that exist for the duration of one job.

## Runner requirements
- Docker executor; `privileged = true` for the `docker:dind` jobs (`ci-image`, `build:images`) — or replace them
  with Kaniko/BuildKit rootless runners.
- GitLab ≥ 16.6 (`image:docker:user`, `FF_NETWORK_PER_BUILD` service aliases, `id_tokens`).
- Outbound access to Docker Hub/ghcr.io/packagist (or mirrors via `DOCKER_IMAGE`, `K8S_TOOLS_IMAGE` overrides).

## Protecting production
1. *Settings → Repository → Protected branches/tags*: default branch and `v*` tags — maintainers only.
2. *Operate → Environments → Protected environments*: `production` — deployment approval by the release-manager group
   (in addition to the manual `approve:production` job).
3. Scope `KUBECONFIG_PRODUCTION` and `PRODUCTION_URL` to environment `production`.
4. The kubeconfig ServiceAccount is namespace-scoped RBAC (apply/patch Deployments, Jobs, ConfigMaps, Services,
   Ingress, CNPG/Strimzi CRs in its namespace only) — no cluster-admin.

## Running pieces locally
```bash
bash scripts/ci/smoke.sh http://localhost:8090            # smoke against the dev stack (header checks warn on http)
docker run --rm -v "$PWD":/w -w /w koalaman/shellcheck-alpine:v0.10.0 shellcheck scripts/ci/*.sh
# optional: gitlab-ci-local --list   (https://github.com/firecow/gitlab-ci-local)
```

— Author: ShokirjonMK
