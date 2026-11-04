<?php

declare(strict_types=1);

/**
 * Template: event payload class (src/Modules/<Module>/Domain/<Entity>Event.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$status = $m->hasStatus();

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Domain;

/**
 * Kafka contract of the {{label}} module: topic {{topic}} (key = {{entityLabelLower}} id → per-record
 * ordering), event types and the payload written to the transactional outbox by {{Entity}}Service.
 */
final class {{Entity}}Event
{
    public const TOPIC = '{{topic}}';
    public const AGGREGATE = '{{entity_snake}}';
    public const SCHEMA_VERSION = 1;

    public const CREATED = {{Entity}}::EVENT_CREATED;
    public const UPDATED = {{Entity}}::EVENT_UPDATED;__STATUS_CONST__
    public const DELETED = {{Entity}}::EVENT_DELETED;

    public const ALL = [self::CREATED, self::UPDATED, __STATUS_ALL__self::DELETED];

    /**
     * Event payload = event-specific data + identifying attributes consumers usually need.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function payload({{Entity}} $entity, array $data): array
    {
        return $data + [
            'tenant_id' => $entity->tenantId,
            'title' => $entity->displayTitle(),__STATUS_PAYLOAD__
            'version' => $entity->version(),
        ];
    }

    /** Human-readable one-liner for timelines. */
    public static function summary(string $type, array $payload): string
    {
        $title = (string) ($payload['title'] ?? '{{entityLabelLower}}');
        return match ($type) {
            self::CREATED => sprintf('{{entityLabel}} "%s" created', $title),
            self::UPDATED => sprintf('{{entityLabel}} "%s" updated: %s', $title, implode(', ', array_keys((array) ($payload['changes'] ?? [])))),__STATUS_SUMMARY__
            self::DELETED => sprintf('{{entityLabel}} "%s" deleted', $title),
            default => $type,
        };
    }
}

PHP, [
    '__STATUS_CONST__' => $status ? "\n    public const STATUS_CHANGED = {{Entity}}::EVENT_STATUS_CHANGED;" : '',
    '__STATUS_ALL__' => $status ? 'self::STATUS_CHANGED, ' : '',
    '__STATUS_PAYLOAD__' => $status ? "\n            'status' => \$entity->status()->value," : '',
    '__STATUS_SUMMARY__' => $status ? "\n            self::STATUS_CHANGED => sprintf('Status changed %s → %s', \$payload['from'] ?? '?', \$payload['to'] ?? '?')," : '',
]);
