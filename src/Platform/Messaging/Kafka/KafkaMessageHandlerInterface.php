<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

/**
 * Handler for plain JSON messages (produced by external systems or {@see KafkaPublisher}), registered with
 * {@see Attribute\KafkaChannel}. Runs inside a database transaction together with the inbox record,
 * so a message is applied exactly once even if Kafka redelivers it. Throw to signal failure
 * (retried in-process, then retry topics / DLQ according to the Kafka settings).
 *
 * Author: ShokirjonMK
 */
interface KafkaMessageHandlerInterface
{
    /** @param array<mixed> $payload decoded JSON object/array */
    public function handle(array $payload, KafkaMessage $message): void;
}
