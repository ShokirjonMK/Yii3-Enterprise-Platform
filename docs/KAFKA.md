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
# Admin → Developer tools → Enterprise Gii → Next steps also creates the missing topics (KafkaTopicCreator, CreateTopics v2)
php yii kafka:consume --group=all             # one forked process per group (config files + #[KafkaChannel])
php yii kafka:handlers                        # groups, topics and handlers (incl. discovered ones)
php yii kafka:publish <topic> '<json>' [--key=K]          # send one JSON message, waits for broker confirmation
php yii kafka:publish <topic> '[{...},{...}]' --batch     # every array element = one message
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

### 3.1 `#[KafkaChannel]` handlers (adopted from `muxtorov98/yii2-kafka`)

The workflow of [muxtorov98/yii2-kafka](https://packagist.org/packages/muxtorov98/yii2-kafka) (Tulqin Muxtorov, MIT) —
attribute-declared handlers, auto-discovery, a worker per topic+group, in-process retry with backoff, flush retries,
`commit_on_failure`, a publish CLI — is built into the platform for Yii3. The package itself is **not** installed: it
requires `yiisoft/yii2` (the whole Yii2 framework, `@common` aliases, `Yii::createObject`) and would bypass the inbox,
retry topics, DLQ, metrics, pause/resume and admin UI. Mapping:

| yii2-kafka | Platform |
|---|---|
| `#[KafkaChannel(topic, group)]` + `KafkaHandlerInterface::handle(array)` | `App\Platform\Messaging\Kafka\Attribute\KafkaChannel` + `KafkaMessageHandlerInterface::handle(array $payload, KafkaMessage $meta)` (or `EventHandlerInterface` with `events: [...]` for platform envelopes) |
| handler auto-discovery in `@common/kafka/handlers` | `KafkaHandlerDiscovery`: every class under `src/Platform`, `src/Modules` |
| `php yii worker/start` (fork per topic+group) | `php yii kafka:consume --group=all` (fork per group, restart with backoff, SIGTERM forwarding) |
| `retry.max_attempts`, `retry.backoff_ms` | `kafka.retry_attempts`, `kafka.retry_backoff_ms` (then retry topics → DLQ) |
| `consumer.commit_on_failure` | `kafka.on_failure` = `retry_dlq` (default) · `dlq` · `skip` (= commit_on_failure true) |
| `producer.flush_timeout_ms`, `flush_retries`, `acks`, `compression`, `linger_ms` | `kafka.producer_*` |
| `consumer.auto_offset_reset`, `max_poll_interval_ms`, `consume_timeout_ms` | `kafka.consumer_*` |
| `security.protocol`, `sasl.*`, `ssl.ca` | `kafka.security_protocol`, `kafka.sasl_*` (password encrypted), `kafka.ssl_ca_location` |
| `common/config/kafka.php` | Admin → 11 Kafka → **Settings** (typed, versioned, audited; env variables win) |
| `kafka-publish/send`, `kafka-publish/batch` | `kafka:publish` (+ `--batch`), `KafkaPublisher::send()/batch()`, Admin → **Publish** |
| — (not in the package) | inbox de-duplication, DLQ + replay, metrics, pause/resume, lag, auto-restart on settings change |

```php
#[KafkaChannel(topic: 'orders.created', group: 'billing', description: 'Invoices for new orders')]
final class OrderCreatedHandler implements KafkaMessageHandlerInterface
{
    public function __construct(private readonly InvoiceService $invoices) {}   // DI autowiring

    public function handle(array $payload, KafkaMessage $message): void
    {
        if (!isset($payload['order_id'])) {
            throw new PermanentMessageFailure('order_id is required');   // → dead letter at once, no retries
        }
        $this->invoices->create((int) $payload['order_id']);               // throw anything else → retried
    }
}
```
- Runs inside a DB transaction with the inbox insert → applied once per consumer group, even on redelivery. The
  idempotency id is the `event_id` / `message_id` header (set by `KafkaPublisher`), otherwise topic+partition+offset.
- The group appears automatically in `kafka:consume --group=all`, in `kafka:handlers` and in Admin → Kafka → **Handlers**.
- Register the topic (Admin → Cluster & topics) and provision it — `KAFKA_AUTO_CREATE_TOPICS_ENABLE=false`.
- Built-in example: `InboundNotificationHandler` (topic `platform.notifications.inbound`, group `notifications-inbound`):
  external systems notify users — `{"user": "<id|username|email>", "subject": "…", "message": "…", "channels": ["inapp","email"]}`.
- Test topic `platform.selftest` (no production consumer) for the Publish tab and integration tests.

### 3.2 Settings changes at runtime

Consumers and the outbox relay compare their configuration with the settings every 5 s. On a change they stop
gracefully (commit, leave the group) and exit with code **75**; Docker (`restart: unless-stopped`) / Kubernetes start
them again with the new configuration. `--group=all` restarts all groups together, because forked children would
otherwise inherit the old configuration. The supervisor closes its DB/Redis connections before forking, so each
child has its own connections.

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
| Handlers: every group with topics, handlers (`events.php` and `#[KafkaChannel]`), status, processes | `admin.kafka.handlers` | `kafka.view` |
| Publish: JSON message or batch to a topic, broker-confirmed, event ids shown | `admin.kafka.publish*` | `kafka.manage` + step-up |
| Settings: connection, SASL/SSL, producer, consumer, retries and failure policy | `admin.kafka.settings*` | `kafka.manage` + step-up (connection/security: superadmin) |

States are never faked: `KAFKA_ENABLED=false` → **Not configured**; broker unreachable → error banner with the
(redacted) librdkafka error; lag shows "Lag unavailable". Audit actions: `kafka.topic.registered/updated/unregistered`,
`kafka.consumer.paused/resumed`, `outbox.requeued`, `outbox.requeued_all`, `kafka.dlq.replayed` (success/failure),
`kafka.message.published`, `settings.changed` (Kafka settings).

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
