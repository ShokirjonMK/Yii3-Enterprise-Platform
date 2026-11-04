<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

/**
 * Domain event raised by the Project aggregate. Published to Kafka topic `projects.events`
 * through the transactional outbox (event type = $type, aggregate = project/$projectId).
 */
final readonly class ProjectEvent
{
    public const TOPIC = 'projects.events';

    public const CREATED = 'project.created';
    public const UPDATED = 'project.updated';
    public const STATUS_CHANGED = 'project.status_changed';
    public const ARCHIVED = 'project.archived';

    public const ALL = [self::CREATED, self::UPDATED, self::STATUS_CHANGED, self::ARCHIVED];

    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $type,
        public string $projectId,
        public array $payload,
    ) {}
}
