<?php

declare(strict_types=1);

/**
 * Template: Kafka consumer handler (src/Modules/<Module>/Infrastructure/<Entity>ActivityProjector.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Infrastructure;

use App\Modules\{{Module}}\Domain\{{Entity}}Event;
use App\Platform\Messaging\EventEnvelope;
use App\Platform\Messaging\EventHandlerInterface;
use App\Shared\Domain\Uuid;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Kafka consumer (group __GROUP__, topic {{topic}}) maintaining the {{entity_snake}}_activity read model
 * shown on the detail pages. Idempotent: KafkaConsumerRunner stores the message id in `inbox` in the same
 * transaction, and UNIQUE(event_id) + ON CONFLICT DO NOTHING protects replays.
 */
final class {{Entity}}ActivityProjector implements EventHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(EventEnvelope $event): void
    {
        if (!in_array($event->type, {{Entity}}Event::ALL, true) || !Uuid::isValid($event->aggregateId)) {
            return;
        }
        $p = $event->payload;
        try {
            $occurred = (new DateTimeImmutable($event->occurredAt ?: 'now'))->format('Y-m-d H:i:s.uP');
        } catch (\Exception) {
            $occurred = (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        }
        $tenant = Uuid::isValid($event->tenantId) ? $event->tenantId : (Uuid::isValid($p['tenant_id'] ?? null) ? $p['tenant_id'] : null);
        $this->db->createCommand(
            'INSERT INTO {{entity_snake}}_activity (event_id, entity_id, tenant_id, event_type, actor_id, summary, payload, occurred_at)
             VALUES (:e, :id, :t, :type, :a, :s, CAST(:payload AS jsonb), :o)
             ON CONFLICT (event_id) DO NOTHING',
            [
                ':e' => $event->id,
                ':id' => strtolower($event->aggregateId),
                ':t' => $tenant,
                ':type' => $event->type,
                ':a' => Uuid::isValid($event->actorId) ? $event->actorId : null,
                ':s' => mb_substr({{Entity}}Event::summary($event->type, $p), 0, 500),
                ':payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':o' => $occurred,
            ],
        )->execute();
    }
}

PHP, ['__GROUP__' => $m->consumerGroup()]);
