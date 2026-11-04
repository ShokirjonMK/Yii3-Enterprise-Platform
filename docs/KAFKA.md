# Kafka: events, outbox, consumers, DLQ

Kafka 3.9 (KRaft) carries domain events between modules and services. The application uses **ext-rdkafka**
(librdkafka) with an idempotent producer (`enable.idempotence`, `acks=all`) and never publishes directly from
business code: events go through the **transactional outbox**. Admin UI: **Control Center → 11 · Kafka Management** (`/admin/kafka`).

```
business tx ──► outbox (same tx) ──► outbox:relay ──► Kafka topic ──► kafka:consume --group=G ──► handler (+ inbox, same tx)
                                                         │ failure: platform.retry.1m → platform.retry.10m → platform.dlq + kafka_dead_letters
```

| Component | Code |
|---|---|
| Outbox | `src/Platform/Messaging/Outbox.php` (`record()` inside the business transaction) |
| Relay | `src/Platform/Messaging/OutboxRelay.php` → `php yii outbox:relay --daemon` |
| Consumer runner | `src/Platform/Messaging/Kafka/KafkaConsumerRunner.php` → `php yii kafka:consume --group=…` |
| Cluster inspection / drift / lag | `src/Platform/Messaging/Kafka/KafkaAdmin.php` |
| Topic provisioning | `src/Console/Command/KafkaProvisionCommand.php`, `src/Admin/Action/Kafka/KafkaProvisioningScript.php` (same output) |
| Consumer groups | `config/common/events.php` + `config/common/events/*.php` |
| Admin UI | `src/Admin/Action/Kafka/*`, `resources/views/admin/kafka/*`, `config/routes/admin/11-kafka.php` |

## 1. Commands

```bash
php yii outbox:relay --daemon --batch=200     # publish pending outbox rows (several relays may run: SKIP LOCKED)
php yii outbox:relay --requeue-failed         # failed rows → pending, exit
php yii kafka:consume --group=platform-default
php yii kafka:consume --group=platform-retry  # built-in: retry topics → original topic after the delay
php yii kafka:consume --group=G --max=100 --idle-exit=10   # bounded run (tests)
php yii worker:health --type=kafka
php yii kafka:provision [-o runtime/kafka-topics.sh] [--replication-factor 3]   # render topic script + show drift
```
Compose services: `relay`, `consumer` (and module consumers). Kubernetes: one Deployment per consumer group, scaled
manually up to the partition count (add partitions before replicas) — operator details in [DEPLOYMENT.md](DEPLOYMENT.md) §4.3 (Strimzi KRaft, `KafkaTopic` resources).

## 2. Publishing an event

```php
$db->transaction(function () use ($outbox, $project) {
    // ... business write ...
    $outbox->record('projects.events', 'project.created', 'project', $project->id, ['name' => $project->name]);
});
```
- Partition key = aggregate id → per-aggregate ordering.
- Kafka down or `KAFKA_ENABLED=false` (or feature flag `messaging.kafka` off) → events wait in `outbox`; nothing is lost.
- Relay failures back off exponentially (max 10 min); after **20** attempts the row becomes `failed` (visible and
  re-queueable in the admin UI).

## 3. Consuming

```php
// config/common/events/projects.php
return ['projects-audit' => ['topics' => ['projects.events'], 'handlers' => ['project.created' => ProjectCreatedHandler::class, '*' => FallbackHandler::class]]];
```
- **At-least-once + idempotent:** offsets are committed after the handler; `inbox (consumer_group, message_id)` is inserted
  in the same DB transaction as the handler, so a redelivered message is skipped.
- **Retries:** failure → `platform.retry.1m` → `platform.retry.10m`; after 3 attempts → `platform.dlq` **and** a row in
  `kafka_dead_letters` (payload, headers, error, attempts). Unparseable messages go straight to the DLQ.
- **Pause/resume:** `consumer_state.paused` is polled every 5 s; partitions are *paused* (no rebalance), heartbeats continue.
- **Shutdown:** SIGTERM → commit, leave the group cleanly.

## 4. Topics

The `kafka_topics` table is the **desired state** (partitions, replication factor, retention, cleanup policy).
php-rdkafka has no stable AdminClient, so topics are created by Kafka's own CLI from the rendered, idempotent script
(`kafka-topics.sh --create --if-not-exists …`) — the `kafka-init` compose service / a Kubernetes Job — or by Strimzi
`KafkaTopic` resources. The admin UI shows **drift**: registered topics missing on the cluster or with fewer
partitions than registered. Partitions can only grow. Unregistering a topic in the UI never deletes the live topic.

Production guidance: replication factor 3, `min.insync.replicas=2`, retention per data class, `KAFKA_TOPIC_PREFIX`
per environment when clusters are shared, SASL/TLS via `KAFKA_SECURITY_PROTOCOL`, `KAFKA_SASL_*`, `KAFKA_SSL_CA_LOCATION`.

## 5. Admin panel (11 · Kafka Management)

| Screen | Route | Permission |
|---|---|---|
| Cluster state (brokers, live topics from metadata), registry with drift, outbox KPIs | `admin.kafka` | `kafka.view` |
| Register / edit topic (validated), unregister | `admin.kafka.topics.*` | `kafka.manage` (+ step-up for unregister) |
| Provisioning script (same as CLI), RF override, download | `admin.kafka.provision*` | `kafka.view` |
| Consumer groups: topics, handlers, processes, per-partition lag (lazy HTMX, `KafkaAdmin::lag`), pause/resume | `admin.kafka.consumers*` | `kafka.view` / `kafka.manage` |
| Outbox monitor: pending/failed/published, list + filters, re-queue one / all failed | `admin.kafka.outbox*` | `kafka.view` / `kafka.manage` + step-up |
| Dead letters: list, redacted payload/headers, replay (`KafkaConsumerRunner::replay`) | `admin.kafka.dlq*` | `kafka.view` / `kafka.manage` + step-up |

States are never faked: `KAFKA_ENABLED=false` → **Not configured**; broker unreachable → error banner with the
(redacted) librdkafka error; lag shows "Lag unavailable". Audit actions: `kafka.topic.registered/updated/unregistered`,
`kafka.consumer.paused/resumed`, `outbox.requeued`, `outbox.requeued_all`, `kafka.dlq.replayed` (success/failure).

Replay re-publishes the stored payload to the **original topic** with header `replayed=1`; consumers dedupe by event id
(inbox), so replaying a message that was in fact processed is harmless for idempotent handlers.

## 6. Troubleshooting

| Symptom | Check |
|---|---|
| Outbox pending grows | relay running? broker reachable (Kafka page)? feature flag `messaging.kafka` on? |
| Outbox `failed` rows | last error column; fix, then *Re-queue all failed* |
| Lag grows | consumer processes alive (Consumer groups page)? group paused? handler errors in logs → DLQ |
| Topic "Missing" | run the provisioning script / apply Strimzi `KafkaTopic` |
| DLQ entries | open the entry, read the error, fix the handler, replay |

Metrics: `outbox_published_total`, `kafka_messages_total{group,result}`, `kafka_handle_seconds`, `kafka_consumer_lag`,
`kafka_dead_letters_total` (see [MONITORING.md](MONITORING.md)).

— Author: ShokirjonMK
