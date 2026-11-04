<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * Kafka event consumer handler. Invoked inside a DB transaction together with the inbox
 * (idempotency) record: a redelivered message is skipped, and a failing handler rolls back both.
 *
 * Register in config/common/events.php: 'consumer-group' => ['topics' => [...], 'handlers' => ['event.type' => Handler::class]]
 */
interface EventHandlerInterface
{
    public function handle(EventEnvelope $event): void;
}
