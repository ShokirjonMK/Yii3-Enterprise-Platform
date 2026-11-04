<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Context\RequestContext;
use JsonException;
use RuntimeException;

/**
 * Direct publishing of JSON messages (integration messages, tests, admin "Publish" tab, `kafka:publish`).
 * Every message gets the headers event_id (idempotency on the consumer side), correlation_id, produced_at
 * and source. Delivery is confirmed by the broker before returning (acks + flush retries from the settings).
 *
 * Domain events that must be consistent with a database change go through the transactional
 * {@see \App\Platform\Messaging\Outbox} instead — this class does not participate in DB transactions.
 *
 * Author: ShokirjonMK
 */
final class KafkaPublisher
{
    public function __construct(
        private readonly KafkaProducer $producer,
        private readonly RequestContext $context,
        private readonly KafkaConfig $config,
    ) {}

    /**
     * @param array<mixed>|string $payload array or JSON string
     * @param array<string,string> $headers
     * @return string event id
     */
    public function send(string $topic, array|string $payload, ?string $key = null, array $headers = []): string
    {
        return $this->batch($topic, [$payload], $key, $headers)[0];
    }

    /**
     * @param list<array<mixed>|string> $payloads
     * @param array<string,string> $headers
     * @return list<string> event ids (all confirmed; throws if any message was not acknowledged)
     */
    public function batch(string $topic, array $payloads, ?string $key = null, array $headers = []): array
    {
        self::assertTopic($topic);
        if ($payloads === []) {
            throw new \InvalidArgumentException('Nothing to publish.');
        }
        $messages = [];
        foreach (array_values($payloads) as $i => $payload) {
            $json = is_string($payload) ? self::normalizeJson($payload) : self::encode($payload);
            $id = Uuid::v7();
            $messages[] = [
                'id' => $id,
                'topic' => $topic,
                'key' => $key ?? $id,
                'value' => $json,
                'headers' => array_map('strval', $headers) + [
                    'event_id' => $id,
                    'correlation_id' => $this->context->correlationId(),
                    'produced_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'source' => $this->config->clientId,
                    'content_type' => 'application/json',
                ],
            ];
        }
        $reports = $this->producer->publishBatch($messages);
        $failed = array_filter($reports, static fn($r) => $r !== true);
        if ($failed !== []) {
            throw new RuntimeException(sprintf('Kafka did not confirm %d of %d message(s): %s', count($failed), count($messages), (string) reset($failed)));
        }
        return array_column($messages, 'id');
    }

    /** Parses a JSON list for batch publishing ("[{...},{...}]"). @return list<array<mixed>> */
    public static function decodeList(string $json): array
    {
        try {
            $items = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw new \InvalidArgumentException('Batch must be a non-empty JSON array of messages.');
        }
        return $items;
    }

    public static function assertTopic(string $topic): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,200}$/', $topic)) {
            throw new \InvalidArgumentException('Invalid topic name (letters, digits, ".", "_", "-").');
        }
    }

    private static function normalizeJson(string $json): string
    {
        try {
            $v = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($v)) {
            throw new \InvalidArgumentException('Message must be a JSON object or array.');
        }
        return self::encode($v);
    }

    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Payload cannot be encoded to JSON: ' . $e->getMessage());
        }
    }
}
