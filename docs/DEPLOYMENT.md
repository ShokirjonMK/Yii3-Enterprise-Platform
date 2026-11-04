# Deployment guide

How the Yii3 Enterprise Platform runs in staging and production: images, Kubernetes topology, high availability for
PostgreSQL / Redis / Kafka, secrets, migrations, rollout/rollback, scaling and capacity.

> **Docker Compose is NOT a high-availability deployment.** `compose.yaml` runs one container per service on a
> single host for development. The Ansible "compose staging" (`infra/ansible`) is a single-VM convenience
> environment for demos and QA. Production HA is only the Kubernetes topology in `infra/kubernetes`.

## 1. Environments

| Environment | Where | Definition | Purpose |
|---|---|---|---|
| local | developer machine | `compose.yaml` (+ `monitoring`, `pooling` profiles) | development, tests (see [LOCAL_SETUP.md](LOCAL_SETUP.md)) |
| CI | GitLab runners | `.gitlab-ci.yml` service containers; `.github/workflows/ci.yml` | lint, unit/integration/API/E2E suites, scans |
| staging (VM) | 1 Docker host | `infra/ansible` (`app_staging` role, prod images) | demo/QA without Kubernetes, **not HA** |
| staging (k8s) | namespace `platform-staging` | `infra/kubernetes/overlays/staging` | release candidate validation, failover drills |
| production | namespace `platform-prod` | `infra/kubernetes/overlays/production` | live traffic, HA |

Cloud resources outside the cluster (namespace guard-rails, S3 buckets for files and backups, least-privilege
storage identities) are created with Terraform in `infra/terraform` (`environments/*.tfvars.example`).

## 2. Images

Both images are built from the same commit and tagged identically (`$CI_COMMIT_SHORT_SHA`, plus the git tag for
releases). OCI labels carry author (ShokirjonMK), source (GitHub), version, revision and build time.

| Image | Dockerfile | Contents |
|---|---|---|
| `…/php:<tag>` | `infra/docker/php/Dockerfile --target prod` | PHP 8.3 FPM + CLI, extensions, `vendor` without dev packages, classmap-authoritative autoloader, regenerated `config/.merge-plan.php`, pre-published UI assets. No composer, git, unzip, gnupg; no tests/docs/infra/CI files. Runs as `www-data` (uid 33); code is root-owned and read-only, only `runtime/` writable. `expose_php=Off`, `display_errors=Off`, `opcache.validate_timestamps=0`, JIT tracing. |
| `…/nginx:<tag>` | `infra/docker/nginx/Dockerfile --build-arg PHP_IMAGE=…/php:<tag>` | nginx 1.27 unprivileged (uid 101), `public/` copied from the php image (identical hashed `public/assets/<hash>`), `server_tokens off`, real client IP from trusted LB ranges, gzip, edge rate limits, `/nginx-health`, `/metrics` restricted to private networks. |

The same php image runs every process type: `php-fpm` (web), `queue:work`, `scheduler:run --daemon`,
`outbox:relay --daemon`, `kafka:consume --group=…`, and the migration Job.

Build locally: `make prod-build IMAGE_REGISTRY=registry.example.com/platform IMAGE_TAG=1.2.3`.

## 3. Kubernetes topology

```
                         Internet
                            │ HTTPS (TLS: cert-manager)
                    ┌───────▼────────┐  rate limit, body size, HSTS, X-Request-ID
                    │ ingress-nginx  │
                    └───────┬────────┘
                            │ HTTP :8080 (NetworkPolicy: only from ingress-nginx / monitoring)
   ┌────────────────────────▼─────────────────────────┐
   │ Deployment web (HPA 3..20, PDB maxUnavailable 1) │   one pod = nginx + php-fpm (127.0.0.1:9000)
   │ ┌─────────┐   ┌──────────┐                       │
   │ │ nginx   │──▶│ php-fpm  │  readOnlyRootFS,      │
   │ └─────────┘   └──────────┘  emptyDir runtime/tmp │
   └──────────────────────────────────────────────────┘
   Deployment worker  (KEDA 2..20 on queue depth/age)   queue:work  (SIGTERM-safe, grace 120 s)
   Deployment scheduler (2, required anti-affinity)     scheduler:run --daemon (PG advisory-lock leader)
   Deployment relay     (2)                             outbox:relay --daemon (SKIP LOCKED claims)
   Deployment consumer-<group> (2 each)                 kafka:consume --group=<group>
   Job platform-migrate (per release)                   migrate:up → rbac:sync → platform:seed
            │                 │                    │                      │
   ┌────────▼──────┐ ┌────────▼────────────┐ ┌─────▼──────────────┐ ┌─────▼──────────┐
   │ CloudNativePG │ │ Redis ×3 + Sentinel │ │ Strimzi Kafka ×3   │ │ S3 (external)  │
   │ platform-pg   │ │ ×3 + HAProxy ×2     │ │ KRaft ctrl+broker  │ │ files, backups │
   │ 1 primary +   │ │ redis-master svc    │ │ TLS + SCRAM-512    │ └────────────────┘
   │ 2 sync-quorum │ └─────────────────────┘ └────────────────────┘
   │ standbys      │──── WAL archive + base backups ───────────────▶ S3 backups bucket
   └───────────────┘
   Observability: Prometheus (operator) ◀ ScrapeConfig/PodMonitor/ServiceMonitor; OTLP → otel-collector (ns observability)
```

Layout (`infra/kubernetes`):

| Path | What |
|---|---|
| `base/kustomization.yaml` | namespace, common labels (`app.kubernetes.io/part-of: yii3-enterprise-platform`) and author annotations, ConfigMap generator (all non-secret env) |
| `base/security/` | ServiceAccounts (token automount off), ExternalSecrets, NetworkPolicies (default deny) |
| `base/app/` | web (+Service), worker, scheduler, relay, consumers, PDBs, HPA, KEDA ScaledObject, Ingress, migration Job |
| `base/data/` | CloudNativePG Cluster + ScheduledBackup, Redis Sentinel StatefulSets + HAProxy, Strimzi Kafka/KafkaNodePool/KafkaUser, KafkaTopics |
| `base/monitoring/` | ScrapeConfig (app via Service), PodMonitors (CNPG, Strimzi), ServiceMonitor (redis_exporter) |
| `overlays/{staging,production}` | namespace, hosts, buckets, storage classes, capacity, image tags, secret-manager paths |
| `examples/` | plain Secret template (no ESO), PostgreSQL PITR recovery Cluster |

Cluster prerequisites: ingress-nginx, cert-manager, external-secrets.io + a `ClusterSecretStore` named
`platform-secret-store`, CloudNativePG operator (ns `cnpg-system`), Strimzi operator (ns `strimzi`, with
`STRIMZI_POD_SECURITY_PROVIDER_CLASS=restricted` because the namespace enforces Pod Security "restricted"),
prometheus-operator (ns `monitoring`), KEDA, and an OpenTelemetry collector in ns `observability`
(`observability/otel/collector.yaml`).

Workload hardening on every app pod: `runAsNonRoot`, fixed uid/gid, `readOnlyRootFilesystem` with emptyDir for
`/app/runtime` and `/tmp`, `capabilities.drop: [ALL]`, `allowPrivilegeEscalation: false`, seccomp `RuntimeDefault`,
no service-account token, `enableServiceLinks: false`, resource requests/limits, zone topology spread plus
host anti-affinity, startup/readiness/liveness probes:

| Workload | Startup | Readiness | Liveness | Shutdown |
|---|---|---|---|---|
| web/nginx | `/nginx-health` | `/health/ready` (PG + Redis) | `/nginx-health` | preStop sleep 10 → `nginx -s quit` |
| web/php | fpm ping | fpm ping | fpm ping | preStop sleep 15 → `SIGQUIT` (graceful), grace 60 s |
| worker | `worker:health --type=queue` | — | `worker:health --type=queue` | SIGTERM finishes current job, grace 120 s |
| consumers | `worker:health --type=kafka` | — | same | commit + leave group, grace 60 s |
| scheduler / relay | — | — | `php yii list --raw <ns>` (runtime boots) | SIGTERM handled, grace 60 s |

## 4. High availability of the data layer

### 4.1 PostgreSQL — CloudNativePG (`base/data/postgres-cluster.yaml`)

- 3 instances (1 primary, 2 hot standbys) on different nodes (`podAntiAffinityType: required`) spread across zones.
- **Synchronous quorum replication** `minSyncReplicas: 1 / maxSyncReplicas: 1`: a commit returns only after one
  standby has the WAL, so a single node loss loses no committed transaction (RPO 0). If both standbys are gone,
  commits wait rather than silently degrading to asynchronous replication.
- Services: `platform-pg-rw` → primary (`DB_HOST`), `platform-pg-ro` → standbys only (`DB_REPLICAS`; the app's
  `DatabaseRouter` keeps a session on the primary for `DB_REPLICA_STICKINESS` seconds after a write). The
  `platform-pg-r` service (any instance) is intentionally not used because it can route "reads" to the primary.
- **Failover**: the operator watches instance managers; when the primary fails it promotes the most advanced
  synchronous standby (`failoverDelay: 0`) and moves the `-rw` Service selector. Typical write outage 10–30 s;
  the PHP connection layer reconnects on the next request/job.
- **Split-brain protection**: a primary only stays primary while the operator/API server agrees. The old primary's
  instance manager **fences** itself (shuts PostgreSQL down) once it sees it is no longer the designated primary or
  cannot confirm it; the `-rw` Service never selects two pods; replication slots (`replicationSlots.highAvailability`)
  keep standbys consistent; a returning old primary is re-cloned/rewound as a standby (`pg_rewind`), never
  promoted. Manual fencing: `kubectl cnpg fencing on platform-pg <pod>`.
- **Backups**: continuous WAL archiving + daily base backups (`ScheduledBackup`, from a standby) to the backups
  bucket via `barmanObjectStore`, retention 30 days. (CNPG ≥ 1.26 recommends the Barman Cloud plugin — same
  bucket layout, migrate when upgrading the operator.)
- **PITR**: `examples/postgres-pitr-recovery.example.yaml` restores into a new cluster at `targetTime`; see §9.
- Planned maintenance: `primaryUpdateMethod: switchover` (rolling minor upgrades switch the primary over instead
  of restarting it).

### 4.2 Redis — Sentinel (`base/data/redis-sentinel.yaml`)

No third-party chart: 3 Redis 7.4 data nodes (StatefulSet, AOF + RDB on PVCs, `requirepass` + `masterauth`
from `platform-secrets`), 3 Sentinels (quorum 2) and a 2-replica HAProxy `redis-master`.

- The application uses `REDIS_MODE=sentinel`, `REDIS_SENTINELS=redis-sentinel-{0,1,2}.redis-sentinel-headless:26379`,
  `REDIS_SENTINEL_SERVICE=mymaster`; Predis asks the Sentinels for the current master on (re)connect.
- The Prometheus storage adapter (ext-redis, no Sentinel support) uses `REDIS_HOST=redis-master`: HAProxy health-checks
  every node with `AUTH`/`INFO replication` and only forwards to the node reporting `role:master`.
- **Failover**: the master is marked objectively down when 2 of 3 Sentinels agree (`down-after-milliseconds 5000`);
  a Sentinel leader elected by majority promotes the best replica, reconfigures the others, and clients follow.
- **Split-brain protection**: promotion needs a Sentinel majority (an isolated minority can never fail over);
  `min-replicas-to-write 1` + `min-replicas-max-lag 10` make a partitioned old master refuse writes after 10 s;
  when it comes back, Sentinel demotes it to a replica of the new master. Nodes announce stable pod DNS names.
- Verified locally with the exact ConfigMap scripts in a 3+3+HAProxy Docker simulation: bootstrap (redis-0 master,
  2 replicas, 2 peer Sentinels), master stop → redis-1 promoted, HAProxy followed (writes OK, data preserved),
  old master rejoined as replica. Not yet exercised on a real cluster (UNVERIFIED on Kubernetes).
- `maxmemory-policy volatile-lru` (only keys with a TTL are evicted: sessions/limits, never locks without TTL).
  Watch `RedisMemoryHigh` / `RedisEvictions`.

### 4.3 Kafka — Strimzi KRaft (`base/data/kafka.yaml`, `kafka-topics.yaml`)

- `KafkaNodePool` of 3 nodes with roles `controller` + `broker` (KRaft, no ZooKeeper), rack-aware across zones.
- `default.replication.factor 3`, `min.insync.replicas 2`, internal topics RF 3 / ISR 2,
  `unclean.leader.election.enable false`, `auto.create.topics.enable false`.
- **Quorum / split-brain**: the KRaft metadata quorum (3 voters) needs a majority to elect the active controller and
  commit metadata (leaders, ISR); a partitioned minority cannot elect leaders. With acks=all + ISR 2 a write is on
  two brokers before it is acknowledged; losing one broker keeps reads and writes available.
- TLS listener `9093` with SCRAM-SHA-512; `KafkaUser platform-app` with prefix ACLs on `platform.*`, `projects.*`
  topics and `platform-*` consumer groups (Secret `platform-app` → `KAFKA_SASL_PASSWORD`; cluster CA
  `platform-kafka-cluster-ca-cert` mounted at `/etc/kafka-ca/ca.crt` → `KAFKA_SSL_CA_LOCATION`).
- Topics: one `KafkaTopic` per entry of `PlatformSeeder::TOPICS` (replicas 3). New module topics → add a
  KafkaTopic CR **and** the seeder entry. In Kubernetes the Topic Operator owns topics; `kafka:provision` is for
  compose/bare metal.
- Delivery semantics: transactional outbox (written in the business transaction) → relay (at-least-once) →
  idempotent consumers (inbox table) → retry topics → DLQ (replay from Admin).

## 5. Secrets

- Nothing secret in git. Kubernetes secrets come from the organisation secret manager via external-secrets.io
  (`base/security/external-secrets.yaml`):

| K8s Secret | Keys | Remote key (prod) |
|---|---|---|
| `platform-secrets` | `APP_SECRET`, `METRICS_TOKEN`, `REDIS_PASSWORD`, `S3_KEY`, `S3_SECRET`, `MAILER_DSN`, `TELEGRAM_BOT_TOKEN` | `prod/platform/app-env` (JSON, extracted) |
| `platform-db-app` (basic-auth) | `username`, `password` | `prod/platform/postgres#app_password` |
| `platform-backup-s3` | `ACCESS_KEY_ID`, `ACCESS_SECRET_KEY` | `prod/platform/s3-backup` |
| `platform-metrics-token` | `token` | `prod/platform/app-env#METRICS_TOKEN` |
| `platform-app` (Strimzi) | `password` | generated by the User Operator |

- Without ESO use `examples/secrets.example.yaml` as a template (`kubectl create secret …`, never commit values).
- Separate identities: the app's S3 identity can only access the files bucket; the backup identity only the
  backups bucket (`infra/terraform/modules/s3-buckets`).
- CI secrets are GitLab protected + masked variables (names in [CI_CD.md](CI_CD.md)).
- Rotation: rotate in the secret manager → ESO refresh (1 h) → `kubectl rollout restart deploy -n <ns>` for env-based
  secrets. `platform-db-app` carries `cnpg.io/reload`, so CNPG applies the new password to the role. Rotating
  `APP_SECRET` invalidates HMAC-signed tokens and encrypted settings — follow docs/SECURITY.md.

## 6. Migrations strategy (zero downtime)

The migration Job runs **before** new pods roll out, while the previous release keeps serving. Therefore every
release must be compatible with both the old and the new code (**expand / contract**):

1. **Expand** (release N): add nullable columns / new tables / new indexes (`CREATE INDEX CONCURRENTLY` in a
   non-transactional migration), add new enum values, write both old and new columns in code.
2. **Migrate data** with a background job or batched migration (no long table locks; set `lock_timeout`).
3. **Switch reads** to the new structure (release N+1).
4. **Contract** (release N+2): drop old columns/constraints once no running version uses them.

Never in one release: rename/drop a column that the running version reads, add `NOT NULL` without default on a
large table, or change a column type in place. Rollback of code is always possible; rollback of schema is not
automatic (`migrate:down` only for an explicitly reversible, data-free migration, by an operator).

Job definition: `base/app/migrate-job.yaml` (`php yii migrate:up --no-interaction && php yii rbac:sync &&
php yii platform:seed`, all idempotent, `backoffLimit: 1`, `activeDeadlineSeconds: 1800`, `ttlSecondsAfterFinished: 86400`).
First installation of an empty cluster: run the Job, then create the first superadmin once with
`kubectl exec deploy/web -c php -- php yii platform:install --skip-migrations` (interactive, prints a one-time password).

## 7. Rollout and rollback

Automated by `scripts/ci/deploy.sh` (GitLab `deploy-staging` / `deploy-production`):

1. `kustomize edit set image yep-php=…/php:$TAG yep-nginx=…/nginx:$TAG` in the overlay.
2. Server-side dry run of the rendered overlay.
3. Delete the previous `platform-migrate` Job, apply only the Job, `kubectl wait --for=condition=complete` (15 min).
   On failure the rollout stops — old pods keep serving the old code on an expanded schema.
4. Apply the overlay; `kubectl rollout status` for web, worker, scheduler, relay and every consumer Deployment.
   Web uses `maxUnavailable: 0`, readiness on `/health/ready`, and preStop drains so no request is dropped.
5. Smoke tests (`scripts/ci/smoke.sh`: `/health/live`, `/health/ready`, login page 200 + security headers, API health,
   `/metrics` rejects anonymous access).

Production needs the manual `approve-production` job (protected environment, default branch or tags only).

**Rollback**: manual GitLab job (`scripts/ci/rollback.sh`) → `kubectl rollout undo deployment/<each>` (optionally
`--to-revision`), then `rollout status`. Equivalent by hand:

```bash
kubectl -n platform-prod rollout history deployment/web
kubectl -n platform-prod rollout undo deployment/web deployment/worker deployment/scheduler deployment/relay \
  deployment/consumer-platform-default deployment/consumer-platform-retry
```

Or redeploy the previous image tag through the pipeline (preferred: keeps git and cluster in sync). The schema stays
on the newer (expanded) version, which the previous release supports by construction (§6).

## 8. Scaling and capacity

| Component | Scaling | Notes |
|---|---|---|
| web | HPA on CPU 65 % / memory 80 %, 3–20 pods | each pod `PHP_FPM_MAX_CHILDREN=20` → ~20 concurrent requests; size by p95 latency × RPS |
| worker | KEDA: pending jobs / 50 per replica, oldest-job age > 60 s, CPU 75 %; 2–20 | `terminationGracePeriodSeconds` must exceed the longest job timeout |
| consumers | manual; ≤ partitions per group (3–6) | add partitions before adding replicas |
| scheduler, relay | fixed 2 | leader lock / SKIP LOCKED; more replicas add no throughput for the scheduler |
| PostgreSQL | vertical + read replicas (`instances`) | connections = Σ(web pods × fpm children + workers + daemons); above ~300 enable PgBouncer (CNPG `Pooler`) |
| Redis | vertical (`maxmemory`) | sessions ≈ 2–5 KB each; metrics keys grow with route × status cardinality |
| Kafka | brokers / partitions | RF 3 → usable disk = raw / 3 |

Starting point for ~500 concurrent users / ~100 RPS: 4 web pods (2 vCPU, 1 GiB php), 3 workers, PostgreSQL 2 vCPU /
4 GiB / 100 GiB, Redis 2 GiB, Kafka 3 × (1 vCPU, 3 GiB, 100 GiB). Validate with a load test before go-live
(UNVERIFIED — no load test has been run yet).

## 9. Disaster recovery

| Scenario | Recovery |
|---|---|
| one node / pod | automatic (PDBs, anti-affinity, operators) |
| PostgreSQL primary loss | automatic failover (§4.1) |
| logical corruption / bad migration | PITR into a new cluster: `examples/postgres-pitr-recovery.example.yaml` (scale writers to 0, set `targetTime`, apply, verify with `php yii audit:verify`, repoint `DB_HOST`/`DB_REPLICAS`, scale up) |
| whole cluster loss | recreate cluster (Terraform), operators, apply overlay with `bootstrap.recovery` from the backups bucket; Kafka topics are recreated by CRs, unpublished events are still in the PostgreSQL outbox |
| S3 files bucket | bucket versioning + lifecycle (Terraform); replicate cross-region if required |

Targets to agree with the business: RPO 0 for single failures (sync replication), ≤ 5 min for PITR
(`archive_timeout`), RTO 30–60 min for full restore. Rehearse a restore on staging every quarter.

## 10. Validation status

| Check | Result |
|---|---|
| `docker build --target prod` (php) + nginx image | built; smoke: non-root, `php -m`, `php yii list`, `expose_php Off`, `opcache.validate_timestamps Off`, no dev packages / composer / git |
| prod php + nginx as one "pod" (shared netns, read-only rootfs, cap-drop ALL) against the dev data services | `/health/live` 200, `/health/ready` 200, `/login` 200 over forwarded HTTPS, assets 200, `/metrics` 401 without token / 200 with token |
| `kustomize build` + `kubeconform -strict` (incl. CRD schemas from the CRDs catalog) | staging 59/59 valid, production 59/59 valid |
| Redis Sentinel scripts + HAProxy failover | verified in a local Docker simulation |
| Live cluster apply, CNPG/Strimzi/KEDA/ESO behaviour, backups/PITR, ingress | **UNVERIFIED** (no cluster available) |

— Author: ShokirjonMK
