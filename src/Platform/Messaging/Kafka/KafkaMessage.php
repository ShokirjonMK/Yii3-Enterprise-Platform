<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

/**
 * Metadata of a consumed message passed to {@see KafkaMessageHandlerInterface::handle()}.
 *
 * Author: ShokirjonMK
 */
final readonly class KafkaMessage
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $id,
        public string $topic,
        public int $partition,
        public int $offset,
        public ?string $key,
        public array $headers,
        public string $group,
        public int $attempt,
    ) {}

    /**
     * Idempotency id: the producer's `event_id`/`message_id` header, otherwise topic+partition+offset
     * (stable across redeliveries of the same record).
     */
    public static function idFor(string $topic, int $partition, int $offset, array $headers): string
    {
        $h = (string) ($headers['event_id'] ?? $headers['message_id'] ?? '');
        if ($h !== '') {
            return mb_substr($h, 0, 64);
        }
        return 'k:' . substr(hash('sha256', $topic . '|' . $partition . '|' . $offset), 0, 40);
    }
}
