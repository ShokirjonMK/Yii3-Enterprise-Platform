# Monitoring, alerting, tracing and logs

All configuration lives in `observability/` and is shared by docker compose (profile `monitoring`) and Kubernetes
(prometheus-operator objects in `infra/kubernetes/base/monitoring/`).

| Component | Compose service / port | Config |
|---|---|---|
| Prometheus v3.5 | `prometheus` → http://localhost:9090 (`PROMETHEUS_PORT`) | `observability/prometheus/prometheus.yml`, `rules/*.yml` |
| Alertmanager v0.28 | `alertmanager` (internal :9093) | `observability/alertmanager/alertmanager.yml`, `templates/telegram.tmpl` |
| Grafana 12.1 | `grafana` → http://localhost:3000 (`GRAFANA_PORT`, user `admin`, password `GRAFANA_ADMIN_PASSWORD`) | `observability/grafana/provisioning`, `dashboards/*.json` |
| OpenTelemetry Collector contrib 0.130 | `otel-collector` (:4317 gRPC, :4318 HTTP, :8888 metrics) | `observability/otel/collector.yaml` |
| Jaeger 2.8 | `jaeger` → http://localhost:16686 (`JAEGER_UI_PORT`) | defaults (OTLP in on :4317) |
| Exporters | `postgres-exporter` :9187, `redis-exporter` :9121, `kafka-exporter` :9308 | env in `compose.yaml` |

Start: `./scripts/platform.sh up monitoring` (or `make up-monitoring`). This first writes `runtime/metrics-token`
from `METRICS_TOKEN` in `.env` (runtime/ is gitignored); Prometheus reads it via `authorization.credentials_file`, so
the token never appears in a committed file. Only the monitoring services can also be started alone:
`docker compose --profile monitoring up -d --no-deps prometheus alertmanager grafana otel-collector jaeger postgres-exporter redis-exporter kafka-exporter`.

## 1. Application endpoints

| Endpoint | Purpose | Auth |
|---|---|---|
| `GET /health/live` | process is up (liveness) | none |
| `GET /health/ready` | PostgreSQL + Redis reachable (readiness); 503 when down | none; full per-dependency details (Kafka, storage, workers, disk…) with `Authorization: Bearer <METRICS_TOKEN>` |
| `GET /metrics` | Prometheus exposition | `Authorization: Bearer <METRICS_TOKEN>` (401 otherwise); production nginx additionally allows only private source networks |

**Aggregation model.** Counters and histograms are stored in Redis (`promphp` Redis adapter, prefix
`yep:prom:`), so all PHP-FPM workers, CLI processes and pods contribute to **one** cluster-wide set of series and
any pod returns the same numbers. Scrape the application **once** (compose: `nginx:8080`; Kubernetes: the `web`
Service via the `platform-app` ScrapeConfig) — scraping every pod would multiply values. Gauges such as queue depth
are computed at scrape time from PostgreSQL by `MetricsCollector`.

## 2. Metrics catalogue

| Metric | Type | Labels | Source |
|---|---|---|---|
| `platform_http_requests_total` | counter | `method`, `route` (route name, `unmatched`), `status` | `MetricsMiddleware` (excludes `/metrics`, `/health*`) |
| `platform_http_request_duration_seconds` | histogram (5 ms … 10 s) | `method`, `route` | `MetricsMiddleware` |
| `platform_jobs_processed_total` | counter | `queue`, `type`, `status` | queue `Worker` |
| `platform_job_duration_seconds` | histogram (50 ms … 15 min) | `queue` | queue `Worker` |
| `platform_queue_jobs` | gauge | `queue`, `status` (pending/running/failed/dead) | collector (jobs table) |
| `platform_queue_oldest_pending_seconds` | gauge | — | collector |
| `platform_workers_alive` | gauge | `type` (queue/kafka) | collector (heartbeat < 90 s) |
| `platform_outbox_events` | gauge | `status` (pending/failed) | collector |
| `platform_outbox_published_total` | counter | `result` (ok/error) | `OutboxRelay` |
| `platform_kafka_messages_total` | counter | `group`, `result` (ok/duplicate/error) | `KafkaConsumerRunner` |
| `platform_kafka_handle_seconds` | histogram | `group` | `KafkaConsumerRunner` |
| `platform_kafka_consumer_lag` | gauge | `group`, `topic`, `partition` | `KafkaConsumerRunner` |
| `platform_kafka_dead_letters` / `_total` | gauge / counter | — / `group` | collector / runner |
| `platform_notifications_total` | counter | `channel`, `result` (sent/failed) | `DeliverNotificationJob` |
| `platform_login_failures_5m` | gauge | — | collector (login_attempts) |
| `platform_sessions_active` | gauge | — | collector (user_sessions) |
| `platform_incidents_open` | gauge | `severity` | collector |
| `platform_db_connections` | gauge | — | collector (`pg_stat_activity`) |
| `platform_collector_errors` | gauge | — | collector failure flag |

Infrastructure: `pg_*` (postgres-exporter) / `cnpg_*` (CloudNativePG), `redis_*` (redis_exporter), `kafka_*`
(kafka-exporter; Strimzi JMX `kafka_server_*`), `otelcol_*`, `up`.

Recording rules (`rules/recording.yml`): `platform:http_requests:rate5m[_by_route]`, `platform:http_error_ratio:rate5m`,
`platform:http_request_duration_seconds:{p50,p95,p99}[_by_route]`, `platform:jobs_processed:rate5m_by_status`,
`platform:job_duration_seconds:p95`, `platform:queue_pending_jobs`, `platform:kafka_consumer_lag:sum`,
`platform:kafka_messages:rate5m`, `platform:outbox_published:rate5m`.

## 3. Dashboards (Grafana folder "Yii3 Enterprise Platform")

| UID | Dashboard | Panels |
|---|---|---|
| `yep-overview` | Platform overview | RPS, 5xx ratio, p95/p99, status breakdown, top routes by RPS and by p95/p99, 5xx per route; jobs by queue/status, oldest job age, live workers, throughput, job p95; consumer lag (app + exporter), DLQ, outbox backlog/publish rate, notifications |
| `yep-data` | Data layer | PostgreSQL up/connections/TPS/cache hit/deadlocks/replication lag/size; Redis up/memory/clients/evictions/ops/hit ratio/replicas; Kafka brokers/URP/lag/topic throughput |
| `yep-security` | Security | failed logins (5 m), active sessions, open incidents, 401/403, auth endpoint status mix, 429 rate-limited requests |

Dashboards are generated JSON (provisioned read-only); change them in git, not in the UI.

## 4. Alerts (`rules/alerts.yml`)

Routing (`alertmanager.yml`): `critical` → Telegram + e-mail (repeat 1 h); `warning` → e-mail (team `security` also
Telegram); `info` → dropped. Inhibitions: `PostgresDown`/`RedisDown` mute their app-level symptoms;
`PlatformAppDown` mutes latency/error alerts; a critical alert mutes the warning of the same name.

Secrets: the Telegram bot token is read from `bot_token_file` and the chat id is substituted at start-up (compose:
alertmanager entrypoint wrapper from `TELEGRAM_BOT_TOKEN` / `TELEGRAM_ALERT_CHAT_ID` in `.env`; Kubernetes: mount a
Secret at `/run/alertmanager` and template the chat id). Without a token the receiver logs delivery errors only.
Messages use HTML parse mode (`templates/telegram.tmpl`).

### PlatformAppDown
`up{job="platform-app"} == 0` for 2 m. Check nginx/web pods, `METRICS_TOKEN` matches the token file / Secret,
NetworkPolicy `web-ingress` allows the monitoring namespace.

### HighErrorRate
5xx > 2 % of requests for 5 m (with > 0.5 RPS). Correlate with deploys, `/health/ready` details, JSON error logs
filtered by `correlation_id`, PostgreSQL/Redis alerts. Roll back if it started with a release (DEPLOYMENT.md §7).

### HighLatencyP95
Overall p95 > 1 s for 10 m (`HighLatencyP95Route`: one route > 2 s for 15 m). Look at the per-route panel,
`pg_stat_statements`, Redis latency, PHP-FPM saturation (HPA at max?).

### QueueBacklog
> 1000 pending jobs on a queue for 10 m. Check KEDA scaling (`kubectl get scaledobject worker`), failing handlers
(`platform_jobs_processed_total{status!="succeeded"}`), paused queues in Admin > Jobs.

### OldestJobAge
Oldest runnable job waits > 5 min. Workers are stalled, saturated or all paused. See also `WorkersDown`.

### WorkersDown
No queue worker heartbeat in 90 s (`ConsumersDown` for Kafka consumers). `kubectl get pods -l app.kubernetes.io/component=worker`,
check liveness (`php yii worker:health --type=queue`) and DB connectivity.

### ConsumerLag
Lag > 1000 messages for 10 m (app gauge or kafka-exporter). Scale the consumer Deployment (≤ partitions), look for
handler errors (`platform_kafka_messages_total{result="error"}`).

### OutboxStuck
> 100 pending outbox events and nothing published for 10 m: relay down or Kafka unreachable/ACL denied. Events are
safe in PostgreSQL; after fixing, they drain automatically. `OutboxFailedEvents`: `php yii outbox:relay --requeue-failed`.

### DLQNotEmpty
Unreplayed dead letters. Inspect payload/error in Admin > Messaging, fix the handler, replay.

### LoginFailureSpike
> 50 failed logins in 5 min (`LoginFailureFlood` > 500 is critical). Review login attempts/IPs in Admin > Security,
tighten ingress `limit-rps`, block ranges at the WAF/LB. Account lockout and per-IP throttling are already active.

### PostgresDown
Exporter cannot connect / no PostgreSQL metrics. Kubernetes: `kubectl cnpg status platform-pg` — failover should
complete in < 30 s; if not, check operator logs and fencing state.

### PostgresReplicationLag
Standby lag > 30 s: reads via `DB_REPLICAS` are stale beyond the 5 s stickiness window. Check standby I/O,
long-running queries on standbys, network. `PostgresConnectionsHigh` (> 80 % of max_connections): reduce
`PHP_FPM_MAX_CHILDREN` × replicas or add a CNPG `Pooler` (PgBouncer). `PostgresWALArchivingFailing`: PITR at risk —
check backup credentials/bucket.

### RedisDown
Sessions, locks, rate limits and metrics are affected. Kubernetes: check Sentinel (`redis-cli -p 26379 sentinel
master mymaster`) and the `redis-master` HAProxy. `RedisMemoryHigh` / `RedisEvictions`: raise `maxmemory`
(and the container limit) — evictions log users out. `RedisReplicaLost`: a replica disconnected.

### KafkaBrokerDown
Broker count dropped or exporter down. With RF 3 / min ISR 2 a second loss stops writes (the outbox buffers events).
`KafkaUnderReplicatedPartitions`: check broker disks/network, `kubectl get kafka platform-kafka -o yaml` conditions.

### DiskSpace
`DiskSpaceLow` < 15 %, `DiskSpaceCritical` < 5 % (node_exporter filesystems or kubelet PVC stats). Needs node-exporter
/ kubelet metrics (kube-prometheus-stack); not available in the compose stack.

### CertificateExpiringSoon
Requires blackbox_exporter probing the public URL (`probe_ssl_earliest_cert_expiry`); cert-manager normally renews
30 days before expiry, so < 14 days means renewal has been failing. Check `kubectl describe certificate platform-tls`.

Other: `DeadJobs`, `JobFailureRate`, `MetricsCollectorFailing`, `CriticalIncidentOpen`, `PostgresDeadlocks`, `TargetDown`.

## 5. Tracing

- Enable with `OTEL_ENABLED=true` (Kubernetes ConfigMap: on; compose `.env`: off by default),
  `OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318` (OTLP/HTTP protobuf), `OTEL_SERVICE_NAME`,
  `OTEL_TRACES_SAMPLER_ARG` (parent-based ratio; base 0.2, production 0.1, default 1.0).
- Spans are batched in-process and flushed at shutdown, so exporter latency does not add to request time.
- Collector pipeline: `otlp` → `memory_limiter` → `attributes/scrub` (deletes `authorization`, `cookie`,
  `set-cookie`, `x-api-key`, `proxy-authorization`, query strings, credential attributes) → `resource` → `batch` →
  Jaeger (OTLP). Verified locally: a test span with an `http.request.header.authorization` attribute arrived in
  Jaeger with that attribute removed.
- Jaeger UI: http://localhost:16686 → service `yii3-platform`. Grafana has a Jaeger datasource (exemplar links from
  Prometheus via `trace_id`).

## 6. Logs and correlation id

- The application writes **JSON lines to stderr** (`ts`, `level`, `service`, `category`, `msg`, `correlation_id`,
  `channel` (web/console), `ctx`), with secrets redacted by `Redactor`. PHP-FPM forwards worker output
  (`catch_workers_output`). nginx writes JSON access logs to stdout (`request_id`, `upstream_time`, real client IP).
- **Correlation id**: ingress-nginx generates `X-Request-ID`; the pod nginx keeps it (or generates one) and passes it to
  PHP as `HTTP_X_REQUEST_ID`; `RequestContext` adopts it (validated `[A-Za-z0-9._:-]{8,128}`) for logs, audit
  records, jobs and outbox events. Search one id across ingress, nginx, app logs and traces.
- Shipping: run a node-level collector (Grafana Alloy / Promtail / Fluent Bit) that tails container stdout/stderr,
  parses JSON and labels by namespace/pod/container; send to Loki (uncomment the Loki datasource in
  `grafana/provisioning/datasources/datasources.yaml`) or to the organisation SIEM. Keep audit logs (database,
  HMAC-signed) separate from operational logs.

## 7. Validation performed

| Command | Result |
|---|---|
| `promtool check config /etc/prometheus/prometheus.yml` (prom/prometheus:v3.5.0) | SUCCESS (2 rule files) |
| `promtool check rules recording.yml alerts.yml` | SUCCESS: 15 recording + 33 alerting rules |
| `amtool check-config alertmanager.yml` (prom/alertmanager:v0.28.1) | SUCCESS (4 receivers, 3 inhibit rules, 1 template) |
| `otelcol-contrib validate --config collector.yaml` (0.130.0) | exit 0 |
| monitoring profile started against the running dev stack | all 9 Prometheus targets `up` (platform-app via nginx with bearer token file, postgres, redis, kafka-exporter, otel-collector, jaeger, alertmanager, grafana, prometheus); 48 rules loaded; recording rules return data; Alertmanager discovered |
| Grafana provisioning | 3 datasources (Prometheus/Jaeger health OK), 3 dashboards in folder "Yii3 Enterprise Platform" |
| Kubernetes ScrapeConfig / PodMonitor / ServiceMonitor | schema-valid (kubeconform); **UNVERIFIED** against a live prometheus-operator |

— Author: ShokirjonMK
