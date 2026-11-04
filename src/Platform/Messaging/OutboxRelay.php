<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Messaging\Kafka\KafkaProducer;
use App\Platform\Monitoring\Metrics;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

/**
 * Publishes outbox rows to Kafka (at-least-once). Multiple relays can run concurrently: rows are
 * claimed with FOR UPDATE SKIP LOCKED inside a short transaction. Per-aggregate ordering is preserved
 * because a batch is ordered by created_at and published with the aggregate id as partition key.
 *
 * Failures: rows stay `pending` with exponential backoff (available_at). After 20 failed attempts a
 * row becomes `failed` (visible in Admin → Kafka, can be re-queued). Nothing is deleted.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly KafkaProducer $producer,
        private readonly FeatureFlagService $flags,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{published:int, failed:int, skipped:bool} */
    public function relayOnce(int $batch = 100): array
    {
        if (!$this->flags->isEnabled('messaging.kafka')) {
            return ['published' => 0, 'failed' => 0, 'skipped' => true];
        }
        return $this->db->transaction(function () use ($batch): array {
            $rows = $this->db->createCommand(
                "SELECT id, topic, partition_key, payload, headers, attempts FROM outbox
                 WHERE status = 'pending' AND available_at <= now()
                 ORDER BY created_at LIMIT :n FOR UPDATE SKIP LOCKED",
                [':n' => $batch],
            )->queryAll();
            if ($rows === []) {
                return ['published' => 0, 'failed' => 0, 'skipped' => false];
            }
            $messages = array_map(static fn($r) => [
                'id' => (string) $r['id'],
                'topic' => (string) $r['topic'],
                'key' => (string) $r['partition_key'],
                'value' => (string) $r['payload'],
                'headers' => (array) (json_decode((string) $r['headers'], true) ?: []),
            ], $rows);

            try {
                $reports = $this->producer->publishBatch($messages);
            } catch (Throwable $e) {
                $reports = array_fill_keys(array_column($messages, 'id'), $e->getMessage());
            }

            $ok = array_keys(array_filter($reports, static fn($r) => $r === true));
            if ($ok !== []) {
                $this->db->createCommand()->update('outbox', ['status' => 'published', 'published_at' => new Expression('now()'), 'last_error' => null], ['id' => $ok])->execute();
            }
            $failed = 0;
            foreach ($rows as $r) {
                $res = $reports[$r['id']] ?? 'no delivery report';
                if ($res === true) {
                    continue;
                }
                $failed++;
                $attempts = (int) $r['attempts'] + 1;
                $this->db->createCommand(
                    "UPDATE outbox SET attempts = :a, last_error = :e,
                        status = CASE WHEN :a >= 20 THEN 'failed' ELSE 'pending' END,
                        available_at = now() + make_interval(secs => LEAST(600, power(2, :a)::int))
                     WHERE id = :id",
                    [':a' => $attempts, ':e' => mb_substr((string) $res, 0, 2000), ':id' => $r['id']],
                )->execute();
            }
            $this->metrics->inc('outbox_published_total', 'Outbox events published', ['result' => 'ok'], count($ok));
            if ($failed > 0) {
                $this->metrics->inc('outbox_published_total', 'Outbox events published', ['result' => 'error'], $failed);
                $this->logger->warning('Outbox relay: {n} events failed to publish', ['n' => $failed]);
            }
            return ['published' => count($ok), 'failed' => $failed, 'skipped' => false];
        });
    }

    public function requeueFailed(): int
    {
        return $this->db->createCommand("UPDATE outbox SET status = 'pending', attempts = 0, available_at = now() WHERE status = 'failed'")->execute();
    }
}
