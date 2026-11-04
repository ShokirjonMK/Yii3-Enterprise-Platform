<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Context\RequestContext;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Transactional outbox. Call {@see record()} inside the same DB transaction as the business change:
 * the event is persisted atomically with the state change and published later by the relay
 * (`./yii outbox:relay`). Kafka being down or disabled never loses events — they wait in `outbox`.
 */
final class Outbox
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RequestContext $context,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @param string|null $partitionKey defaults to the aggregate id (per-aggregate ordering)
     */
    public function record(
        string $topic,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        int $schemaVersion = 1,
        ?string $partitionKey = null,
    ): EventEnvelope {
        $envelope = new EventEnvelope(
            Uuid::v7(),
            $eventType,
            $schemaVersion,
            $aggregateType,
            $aggregateId,
            $payload,
            (new DateTimeImmutable())->format(DATE_RFC3339_EXTENDED),
            $this->context->correlationId(),
            $this->context->tenantId(),
            $this->context->userId(),
        );
        $this->db->createCommand()->insert('outbox', [
            'id' => $envelope->id,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'topic' => $topic,
            'partition_key' => $partitionKey ?? $aggregateId,
            'payload' => $envelope->toJson(),
            'headers' => json_encode($envelope->headers()),
            'correlation_id' => $envelope->correlationId,
            'tenant_id' => $envelope->tenantId,
        ])->execute();
        return $envelope;
    }
}
