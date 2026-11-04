<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * Versioned event envelope (CloudEvents-inspired). Wire format = JSON value; metadata also in headers.
 *
 * Schema evolution rules (docs/KAFKA.md): add optional fields only within a schema_version; breaking
 * changes bump schema_version and consumers must accept N and N-1.
 */
final readonly class EventEnvelope
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public int $schemaVersion,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
        public ?string $actorId = null,
    ) {}

    public function toJson(): string
    {
        return (string) json_encode([
            'id' => $this->id,
            'type' => $this->type,
            'schema_version' => $this->schemaVersion,
            'aggregate' => ['type' => $this->aggregateType, 'id' => $this->aggregateId],
            'occurred_at' => $this->occurredAt,
            'correlation_id' => $this->correlationId,
            'tenant_id' => $this->tenantId,
            'actor_id' => $this->actorId,
            'payload' => $this->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($d) || !isset($d['id'], $d['type'], $d['payload'])) {
            throw new \InvalidArgumentException('Not a platform event envelope.');
        }
        return new self(
            (string) $d['id'],
            (string) $d['type'],
            (int) ($d['schema_version'] ?? 1),
            (string) ($d['aggregate']['type'] ?? ''),
            (string) ($d['aggregate']['id'] ?? ''),
            (array) $d['payload'],
            (string) ($d['occurred_at'] ?? ''),
            $d['correlation_id'] ?? null,
            $d['tenant_id'] ?? null,
            $d['actor_id'] ?? null,
        );
    }

    /** @return array<string,string> Kafka headers */
    public function headers(): array
    {
        return array_filter([
            'event_id' => $this->id,
            'event_type' => $this->type,
            'schema_version' => (string) $this->schemaVersion,
            'correlation_id' => (string) $this->correlationId,
            'tenant_id' => (string) $this->tenantId,
            'content_type' => 'application/json',
        ], static fn($v) => $v !== '');
    }
}
