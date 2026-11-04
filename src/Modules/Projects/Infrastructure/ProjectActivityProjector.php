<?php

declare(strict_types=1);

namespace App\Modules\Projects\Infrastructure;

use App\Modules\Projects\Domain\ProjectEvent;
use App\Platform\Messaging\EventEnvelope;
use App\Platform\Messaging\EventHandlerInterface;
use App\Shared\Domain\Uuid;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Kafka consumer (group `projects-audit`, topic `projects.events`) maintaining the
 * `project_activity` read model used by the project timeline.
 *
 * Idempotency: KafkaConsumerRunner records every message id in `inbox` inside the same transaction
 * as this handler; the UNIQUE(event_id) + ON CONFLICT DO NOTHING is a second line of defence for replays.
 */
final class ProjectActivityProjector implements EventHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(EventEnvelope $event): void
    {
        if (!in_array($event->type, ProjectEvent::ALL, true) || !Uuid::isValid($event->aggregateId)) {
            return;
        }
        $p = $event->payload;
        try {
            $occurred = (new DateTimeImmutable($event->occurredAt ?: 'now'))->format('Y-m-d H:i:s.uP');
        } catch (\Exception) {
            $occurred = (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        }
        $this->db->createCommand(
            'INSERT INTO project_activity (event_id, project_id, tenant_id, event_type, actor_id, summary, payload, occurred_at)
             VALUES (:e, :p, :t, :type, :a, :s, CAST(:payload AS jsonb), :o)
             ON CONFLICT (event_id) DO NOTHING',
            [
                ':e' => $event->id,
                ':p' => $event->aggregateId,
                ':t' => Uuid::isValid($event->tenantId) ? $event->tenantId : (Uuid::isValid($p['tenant_id'] ?? null) ? $p['tenant_id'] : null),
                ':type' => $event->type,
                ':a' => Uuid::isValid($event->actorId) ? $event->actorId : null,
                ':s' => mb_substr(self::summary($event->type, $p), 0, 500),
                ':payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':o' => $occurred,
            ],
        )->execute();
    }

    public static function summary(string $type, array $p): string
    {
        $name = (string) ($p['name'] ?? $p['code'] ?? 'project');
        return match ($type) {
            ProjectEvent::CREATED => sprintf('Project "%s" created', $name),
            ProjectEvent::UPDATED => sprintf('Project "%s" updated: %s', $name, implode(', ', array_keys((array) ($p['changes'] ?? [])))),
            ProjectEvent::STATUS_CHANGED => sprintf('Status changed %s → %s', $p['from'] ?? '?', $p['to'] ?? '?'),
            ProjectEvent::ARCHIVED => sprintf('Project "%s" archived', $name),
            default => $type,
        };
    }
}
