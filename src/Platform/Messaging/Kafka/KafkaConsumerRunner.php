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
 *  - consumer-lag gauge per partition.
 */
final class KafkaConsumerRunner
{
    public const RETRY_TOPICS = ['platform.retry.1m' => 60, 'platform.retry.10m' => 600];
    public const DLQ_TOPIC = 'platform.dlq';
    private const MAX_ATTEMPTS = 3;

    private bool $stop = false;
    private bool $paused = false;
    private float $lastStateCheck = 0;

    public function __construct(
        private readonly KafkaConfig $config,
        private readonly KafkaProducer $producer,
        private readonly ConnectionInterface $db,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $context,
        private readonly Metrics $metrics,
        private readonly array $groups,
    ) {}

    /** @return array<string, array{topics:list<string>, handlers:array<string,string>}> */
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
                $message = $consumer->consume(1000);
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
                $isRetry ? $this->handleRetry($message) : $this->process($group, $def['handlers'], $message);
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

    /** @param array<string,string> $handlers */
    public function process(string $group, array $handlers, Message $message): void
    {
        $headers = $message->headers ?? [];
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
            $this->db->transaction(function () use ($group, $event, $message, $handlerClass): void {
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
            });
            $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'ok']);
            $this->metrics->observe('kafka_handle_seconds', 'Kafka handler duration', (hrtime(true) - $start) / 1e9, ['group' => $group]);
        } catch (Throwable $e) {
            $attempts = (int) ($headers['attempts'] ?? 0) + 1;
            $this->metrics->inc('kafka_messages_total', 'Consumed Kafka messages', ['group' => $group, 'result' => 'error']);
            $this->logger->warning('Kafka handler failed for {type} (attempt {a}): {e}', ['type' => $event->type, 'a' => $attempts, 'e' => $e->getMessage()]);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->deadLetter($group, $message, $e->getMessage(), $attempts);
                return;
            }
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
        $paused = (bool) (new Query($this->db))->select('paused')->from('consumer_state')->where(['consumer_group' => $group])->scalar();
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
