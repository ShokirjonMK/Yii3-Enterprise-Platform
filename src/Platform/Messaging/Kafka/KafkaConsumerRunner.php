<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use App\Platform\Messaging\EventEnvelope;
use App\Platform\Messaging\EventHandlerInterface;
use App\Platform\Monitoring\Metrics;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Consumer group runner with:
 *  - manual offset commit after successful processing (at-least-once),
 *  - idempotent processing: `inbox (consumer_group, message_id)` insert in the same transaction as the handler,
 *  - retries through delayed retry topics (platform.retry.1m → platform.retry.10m) and finally the DLQ
 *    topic `platform.dlq` + `kafka_dead_letters` table (replayable from the admin panel),
 *  - safe pause/resume from the admin panel (`consumer_state.paused`) — partitions are paused, not closed,
 *  - graceful shutdown on SIGTERM (commits, leaves the group cleanly),
 *  - consumer-lag gauge per partition,
 *  - handlers declared with #[KafkaChannel] (auto-discovered): platform envelopes or plain JSON messages
 *    ({@see KafkaMessageHandlerInterface}); immediate in-process retries with backoff and a configurable
 *    failure policy (retry_dlq | dlq | skip) — ideas adopted from muxtorov98/yii2-kafka (MIT),
 *  - automatic restart when the Kafka settings change (graceful exit, the supervisor/orchestrator restarts).
 *
 * Author: ShokirjonMK
 */
final class KafkaConsumerRunner
{
    public const RETRY_TOPICS = ['platform.retry.1m' => 60, 'platform.retry.10m' => 600];
    public const DLQ_TOPIC = 'platform.dlq';
    /** exit code of a consumer that stopped because its configuration changed (restart expected) */
    public const EXIT_CONFIG_CHANGED = 75;

    private bool $stop = false;
    private bool $paused = false;
    private float $lastStateCheck = 0;
    private bool $configChanged = false;

    public function __construct(
        private readonly KafkaConfig $config,
        private readonly KafkaProducer $producer,
        private readonly ConnectionInterface $db,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $context,
        private readonly Metrics $metrics,
        private readonly array $groups,
        private readonly ?KafkaConfigFactory $configFactory = null,
    ) {}

    /** True when the last run() stopped because the Kafka settings changed. */
    public function configChanged(): bool
    {
        return $this->configChanged;
    }

    /** @return array<string, array{topics:list<string>, handlers:array<string,string>, raw?:array<string,list<string>>}> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function run(string $group, ?int $maxMessages = null, int $idleExitSeconds = 0): int
    {
        $def = $this->groups[$group] ?? throw new \InvalidArgumentException("Unknown consumer group \"$group\".");
        $isRetry = $group === 'platform-retry';
        $topics = array_map(fn($t) => $this->config->topic($t), $isRetry ? array_keys(self::RETRY_TOPICS) : $def['topics']);

        $consumer = new KafkaConsumer($this->config->consumerConf($group));
        $consumer->subscribe($topics);
        $this->installSignals();
        $this->logger->info('Kafka consumer {g} subscribed to {t}', ['g' => $group, 't' => implode(',', $topics)]);

        $processed = 0;
        $idleSince = microtime(true);
        try {
            while (!$this->stop) {
                $this->syncPauseState($consumer, $group);
                $message = $consumer->consume($this->config->consumerPollTimeoutMs);
                if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                    if ($idleExitSeconds > 0 && microtime(true) - $idleSince > $idleExitSeconds) {
                        break;
                    }
                    continue;
                }
                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    $this->logger->warning('Kafka consumer error: {e}', ['e' => $message->errstr()]);
                    continue;
                }
                $idleSince = microtime(true);
                $isRetry ? $this->handleRetry($message) : $this->process($group, $def['handlers'], $message, $def['raw'] ?? []);
                $consumer->commit($message);
                $this->reportLag($consumer, $message, $group);
                $processed++;
                if ($maxMessages !== null && $processed >= $maxMessages) {
                    break;
                }
            }
        } finally {
            $consumer->unsubscribe();
            $consumer->close();
            $this->logger->info('Kafka consumer {g} stopped after {n} messages', ['g' => $group, 'n' => $processed]);
        }
        return $processed;
    }

    /**
     * @param array<string,string> $handlers event type → EventHandlerInterface class
     * @param array<string,list<string>> $raw topic (without prefix) → KafkaMessageHandlerInterface classes
     */
    public function process(string $group, array $handlers, Message $message, array $raw = []): void
    {
        $headers = $message->headers ?? [];
        $rawHandlers = $raw[$this->unprefixed((string) $message->topic_name)] ?? [];
        if ($rawHandlers !== []) {
            $this->processRaw($group, $rawHandlers, $message);
            return;
        }
        try {
            $event = EventEnvelope::fromJson((string) $message->payload);
        } catch (Throwable $e) {
            $this->deadLetter($group, $message, 'Unparseable message: ' . $e->getMessage(), (int) ($headers['attempts'] ?? 1));
            return;
        }
        $handlerClass = $handlers[$event->type] ?? $handlers['*'] ?? null;
        if ($handlerClass === null) {
            return; // not interested in this event type
        }
        $this->context->reset();
        $this->context->setChannel('consumer');
        $this->context->setCorrelationId($event->correlationId);
        $this->context->setTenantId($event->tenantId);

        $start = hrtime(true);
        try {
            $this->withRetries(fn() => $this->db->transaction(function () use ($group, $event, $message, $handlerClass): void {
                $fresh = $this->db->createCommand(
                    'INSERT INTO inbox (consumer_group, message_id, topic) VALUES (:g, :m, :t) ON CONFLICT DO NOTHING RETURNING message_id',
                    [':g' => $group, ':m' => $event->id, ':t' => $message->topic_name],
                )->queryScalar();
                if ($fresh === false || $fresh === null) {
                    $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'duplicate']);
                    return; // already processed: idempotent skip
                }
                $handler = $this->container->get($handlerClass);
                if (!$handler instanceof EventHandlerInterface) {
                    throw new \LogicException("$handlerClass must implement EventHandlerInterface");
                }
                $handler->handle($event);
            }), $group, $event->type);
            $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'ok']);
            $this->metrics->observe('kafka_handle_seconds', 'Kafka handler duration', (hrtime(true) - $start) / 1e9, ['group' => $group]);
        } catch (Throwable $e) {
            $this->fail($group, $message, $event->type, $e);
        }
    }

    /** @param list<string> $classes */
    private function processRaw(string $group, array $classes, Message $message): void
    {
        $headers = $message->headers ?? [];
        if (isset($headers['target_group']) && $headers['target_group'] !== $group) {
            return; // redelivery meant for another consumer group
        }
        try {
            $payload = json_decode((string) $message->payload, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \UnexpectedValueException('Message must be a JSON object or array.');
            }
        } catch (Throwable $e) {
            $this->deadLetter($group, $message, 'Unparseable message: ' . $e->getMessage(), (int) ($headers['attempts'] ?? 1));
            return;
        }
        $id = KafkaMessage::idFor((string) ($headers['original_topic'] ?? $message->topic_name), (int) $message->partition, (int) $message->offset, $headers);
        $this->context->reset();
        $this->context->setChannel('consumer');
        if (isset($headers['correlation_id'])) {
            $this->context->setCorrelationId((string) $headers['correlation_id']);
        }
        $start = hrtime(true);
        $attempt = 0;
        try {
            $this->withRetries(function () use ($group, $classes, $message, $payload, $headers, $id, &$attempt): void {
                $attempt++;
                $this->db->transaction(function () use ($group, $classes, $message, $payload, $headers, $id, $attempt): void {
                    $fresh = $this->db->createCommand(
                        'INSERT INTO inbox (consumer_group, message_id, topic) VALUES (:g, :m, :t) ON CONFLICT DO NOTHING RETURNING message_id',
                        [':g' => $group, ':m' => $id, ':t' => $message->topic_name],
                    )->queryScalar();
                    if ($fresh === false || $fresh === null) {
                        $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'duplicate']);
                        return;
                    }
                    $meta = new KafkaMessage($id, (string) $message->topic_name, (int) $message->partition, (int) $message->offset,
                        $message->key, array_map('strval', $headers), $group, (int) ($headers['attempts'] ?? 0) + $attempt);
                    foreach ($classes as $class) {
                        $handler = $this->container->get($class);
                        if (!$handler instanceof KafkaMessageHandlerInterface) {
                            throw new \LogicException("$class must implement KafkaMessageHandlerInterface");
                        }
                        $handler->handle($payload, $meta);
                    }
                });
            }, $group, (string) $message->topic_name);
            $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'ok']);
            $this->metrics->observe('kafka_handle_seconds', 'Kafka handler duration', (hrtime(true) - $start) / 1e9, ['group' => $group]);
        } catch (Throwable $e) {
            $this->fail($group, $message, (string) $message->topic_name, $e);
        }
    }

    /** Immediate retries in the same process (kafka.retry_attempts × kafka.retry_backoff_ms, linear backoff). */
    private function withRetries(callable $fn, string $group, string $what): void
    {
        $max = max(1, $this->config->retryAttempts);
        for ($i = 1; ; $i++) {
            try {
                $fn();
                return;
            } catch (Throwable $e) {
                if ($i >= $max || $this->stop || $e instanceof PermanentMessageFailure) {
                    throw $e;
                }
                $this->logger->notice('Kafka handler retry {i}/{n} for {w} in {g}: {e}', ['i' => $i, 'n' => $max, 'w' => $what, 'g' => $group, 'e' => $e->getMessage()]);
                if ($this->config->retryBackoffMs > 0) {
                    usleep($this->config->retryBackoffMs * 1000 * $i);
                }
            }
        }
    }

    /** Failure policy after the immediate retries: retry topics → DLQ (default), DLQ at once, or skip. */
    private function fail(string $group, Message $message, string $what, Throwable $e): void
    {
        $headers = $message->headers ?? [];
        $attempts = (int) ($headers['attempts'] ?? 0) + 1;
        $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'error']);
        $this->logger->warning('Kafka handler failed for {type} (delivery {a}): {e}', ['type' => $what, 'a' => $attempts, 'e' => $e->getMessage()]);
        if ($this->config->onFailure === 'skip') {
            $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'skipped']);
            return;
        }
        if ($e instanceof PermanentMessageFailure || $this->config->onFailure === 'dlq' || $attempts >= max(1, $this->config->maxDeliveries)) {
            $this->deadLetter($group, $message, $e->getMessage(), $attempts);
            return;
        }
        // keep the idempotency id stable: the redelivered copy gets a new offset
        $headers['message_id'] ??= $headers['event_id'] ?? KafkaMessage::idFor((string) $message->topic_name, (int) $message->partition, (int) $message->offset, $headers);
        $retryTopic = array_keys(self::RETRY_TOPICS)[min($attempts - 1, count(self::RETRY_TOPICS) - 1)];
        $delay = self::RETRY_TOPICS[$retryTopic];
        $this->producer->publish($retryTopic, (string) $message->key, (string) $message->payload, array_merge($headers, [
            'attempts' => (string) $attempts,
            'original_topic' => $message->topic_name,
            'target_group' => $group,
            'not_before' => (string) (time() + $delay),
            'last_error' => mb_substr($e->getMessage(), 0, 500),
        ]));
    }

    private function unprefixed(string $topic): string
    {
        $p = $this->config->topicPrefix;
        return $p !== '' && str_starts_with($topic, $p) ? substr($topic, strlen($p)) : $topic;
    }

    /** Retry-topic consumer: waits until not_before, then re-delivers to the original topic. */
    private function handleRetry(Message $message): void
    {
        $h = $message->headers ?? [];
        $wait = (int) ($h['not_before'] ?? 0) - time();
        while ($wait > 0 && !$this->stop) {
            sleep(min($wait, 5));
            $wait = (int) ($h['not_before'] ?? 0) - time();
        }
        $this->producer->publish((string) ($h['original_topic'] ?? self::DLQ_TOPIC), (string) $message->key, (string) $message->payload, $h);
    }

    public function deadLetter(string $group, Message $message, string $error, int $attempts): void
    {
        $headers = $message->headers ?? [];
        try {
            $this->producer->publish(self::DLQ_TOPIC, (string) $message->key, (string) $message->payload, $headers + ['error' => mb_substr($error, 0, 500), 'source_group' => $group]);
        } catch (Throwable $e) {
            $this->logger->error('Could not publish to DLQ topic: {e}', ['e' => $e->getMessage()]);
        }
        $this->db->createCommand()->insert('kafka_dead_letters', [
            'consumer_group' => $group,
            'topic' => (string) ($headers['original_topic'] ?? $message->topic_name),
            'partition' => (int) $message->partition,
            'offset' => (int) $message->offset,
            'message_key' => $message->key,
            'message_id' => $headers['event_id'] ?? null,
            'payload' => (string) $message->payload,
            'headers' => json_encode($headers),
            'error' => mb_substr($error, 0, 4000),
            'attempts' => $attempts,
        ])->execute();
        $this->metrics->inc('kafka_dead_letters_total', 'Messages sent to DLQ', ['group' => $group]);
    }

    /** Re-publishes a stored dead letter to its original topic (admin action). */
    public function replay(int $deadLetterId, ?string $actorId): void
    {
        $row = (new Query($this->db))->from('kafka_dead_letters')->where(['id' => $deadLetterId])->one()
            ?? throw new \App\Shared\Domain\Exception\NotFoundException('Dead letter not found.');
        $headers = (array) (json_decode((string) $row['headers'], true) ?: []);
        unset($headers['attempts'], $headers['not_before'], $headers['error']);
        $this->producer->publish((string) $row['topic'], (string) $row['message_key'], (string) $row['payload'], $headers + ['replayed' => '1']);
        $this->db->createCommand()->update('kafka_dead_letters', ['replayed_at' => new Expression('now()'), 'replayed_by' => $actorId], ['id' => $deadLetterId])->execute();
    }

    private function syncPauseState(KafkaConsumer $consumer, string $group): void
    {
        if (microtime(true) - $this->lastStateCheck < 5) {
            return;
        }
        $this->lastStateCheck = microtime(true);
        if ($this->configFactory !== null && $this->configFactory->fresh()->fingerprint() !== $this->config->fingerprint()) {
            $this->logger->notice('Kafka settings changed; consumer group {g} restarts', ['g' => $group]);
            $this->configChanged = true;
            $this->stop = true;
            return;
        }
        $paused = (bool) (new Query($this->db))->select('paused')->from('consumer_state')->where(['consumer_group' => $group])->scalar()
            // global switch: Kafka feature off → every group pauses (messages stay in Kafka, nothing is lost)
            || !(bool) $this->db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'messaging.kafka'")->queryScalar();
        if ($paused !== $this->paused) {
            $assignment = $consumer->getAssignment();
            $paused ? $consumer->pause($assignment) : $consumer->resume($assignment);
            $this->paused = $paused;
            $this->logger->notice('Consumer group {g} {s}', ['g' => $group, 's' => $paused ? 'paused' : 'resumed']);
        }
        $this->db->createCommand()->upsert('workers', [
            'id' => 'kafka:' . $group . ':' . gethostname() . ':' . getmypid(), 'type' => 'kafka', 'hostname' => (string) gethostname(),
            'pid' => getmypid(), 'queues' => $group, 'status' => $paused ? 'idle' : 'busy', 'heartbeat_at' => new Expression('now()'),
        ])->execute();
    }

    private function reportLag(KafkaConsumer $consumer, Message $message, string $group): void
    {
        static $last = 0.0;
        if (microtime(true) - $last < 10) {
            return;
        }
        $last = microtime(true);
        try {
            $low = $high = 0;
            $consumer->queryWatermarkOffsets($message->topic_name, $message->partition, $low, $high, 2000);
            $lag = max(0, $high - $message->offset - 1);
            $this->metrics->gauge('kafka_consumer_lag', 'Consumer lag (messages)', (float) $lag, ['group' => $group, 'topic' => $message->topic_name, 'partition' => (string) $message->partition]);
        } catch (Throwable) {
        }
    }

    private function installSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $h = function (): void {
                $this->stop = true;
            };
            pcntl_signal(SIGTERM, $h);
            pcntl_signal(SIGINT, $h);
        }
    }
}
