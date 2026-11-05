<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Kafka Event: event payload class (topic, types, payload builder), outbox usage in the service (same
 * transaction), consumer handler maintaining an activity read model, src/Modules/<Module>/config/events.php and
 * the read-model / topic-registry migration.
 */
final class KafkaEventGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'event';
    }

    public static function getName(): string
    {
        return 'Kafka Event';
    }

    public static function getDescription(): string
    {
        return 'Event payload class, transactional outbox usage in the service, consumer handler (activity read model) and the consumer-group config.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Domain/' . $m->entity . 'Event.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Domain/' . $m->entity . 'Event.php') => 'event.php',
            $m->modulePath('Infrastructure/' . $m->entity . 'ActivityProjector.php') => 'projector.php',
            $m->modulePath('config/events.php') => 'config.php',
            $m->migrationPath('Events') => 'migration.php',
        ];
    }
}
