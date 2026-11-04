<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka\Attribute;

use Attribute;

/**
 * Declares a Kafka handler: which topic it listens to and in which consumer group it runs.
 * Handlers are discovered automatically ({@see \App\Platform\Messaging\Kafka\KafkaHandlerDiscovery}) — no
 * config file entry needed; `kafka:consume --group=all` starts one process per group.
 *
 *   #[KafkaChannel(topic: 'orders.created', group: 'billing')]
 *   final class OrderCreatedHandler implements KafkaMessageHandlerInterface { ... }      // raw JSON messages
 *
 *   #[KafkaChannel(topic: 'projects.events', group: 'search-indexer', events: ['project.created'])]
 *   final class IndexProject implements EventHandlerInterface { ... }                     // platform envelopes
 *
 * Repeatable: one handler may listen to several topics/groups. Idea adopted from muxtorov98/yii2-kafka
 * (Tulqin Muxtorov, MIT) and re-implemented for Yii3 + the platform's inbox/retry/DLQ pipeline.
 *
 * Author: ShokirjonMK
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class KafkaChannel
{
    /**
     * @param list<string> $events platform event types (EventHandlerInterface only); empty = all types ("*")
     */
    public function __construct(
        public string $topic,
        public string $group = 'platform-default',
        public array $events = [],
        public string $description = '',
    ) {}
}
