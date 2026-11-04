<?php

declare(strict_types=1);

/**
 * Template: aggregate root (src/Modules/<Module>/Domain/<Entity>.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$status = $m->statusField();
$hydrate = [];
foreach ($m->fields as $f) {
    $hydrate[] = '            ' . FieldCode::q($f->name) . ' => ' . FieldCode::fromRow($f) . ',';
}
$getters = [];
foreach ($m->fields as $f) {
    if ($f->isStatus()) {
        $getters[] = "    public function status(): {$m->entity}Status\n    {\n        return {$m->entity}Status::from((string) \$this->values['status']);\n    }";
        continue;
    }
    $getters[] = "    public function {$f->camel()}(): {$f->phpType()}\n    {\n        return \$this->values[" . FieldCode::q($f->name) . "];\n    }";
}
$display = $m->displayField();
$titleExpr = $display !== null ? "(string) (\$this->values[" . FieldCode::q($display->name) . "] ?? \$this->id)" : '$this->id';

$statusConst = $status ? "\n    public const EVENT_STATUS_CHANGED = '{{entity_snake}}.status_changed';" : '';
$statusInit = $status ? "\n        \$values['status'] = {{Entity}}Status::initial()->value;" : '';
$statusMethods = $status ? <<<'PHP'

    /** Lifecycle transition; returns the previous state. */
    public function changeStatus({{Entity}}Status $to, string $comment = ''): {{Entity}}Status
    {
        $this->assertNotDeleted();
        $from = $this->status();
        $from->assertTransitionTo($to);
        $this->values['status'] = $to->value;
        $this->record(self::EVENT_STATUS_CHANGED, ['from' => $from->value, 'to' => $to->value, 'comment' => mb_substr($comment, 0, 500)]);
        return $from;
    }

PHP : '';
$terminalCheck = $status ? <<<'PHP'

        if ($this->status()->isTerminal()) {
            throw new ConflictException('{{entityLabel}} in state "' . $this->status()->label() . '" is read-only.', '{{entity_snake}}_read_only');
        }
PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Domain;

use App\Shared\Domain\Exception\ConflictException;
use DateTimeImmutable;

/**
 * {{entityLabel}} aggregate root: attributes validated and normalised by {{Entity}}Rules,
 * __LIFECYCLE__soft delete, optimistic-lock version and recorded domain events
 * (written to the outbox by the application service inside the business transaction).
 */
final class {{Entity}}
{
    public const EVENT_CREATED = '{{entity_snake}}.created';
    public const EVENT_UPDATED = '{{entity_snake}}.updated';__STATUS_CONST__
    public const EVENT_DELETED = '{{entity_snake}}.deleted';

    /** @var list<array{type:string, payload:array<string,mixed>}> */
    private array $events = [];

    /** @param array<string,mixed> $values normalised attribute values (column => value) */
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        private array $values,
        private int $version,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
    ) {}

    /** @param array<string,mixed> $input raw input (validated here) */
    public static function create(string $id, string $tenantId, array $input, ?string $createdBy): self
    {
        $values = {{Entity}}Rules::normalize($input, true);__STATUS_INIT__
        $entity = new self($id, $tenantId, $values, 1, $createdBy, new DateTimeImmutable(), null, null);
        $entity->record(self::EVENT_CREATED, $entity->toArray());
        return $entity;
    }

    /** @param array<string,mixed> $r database row */
    public static function fromRow(array $r): self
    {
        return new self(
            (string) $r['id'],
            (string) $r['tenant_id'],
            self::hydrate($r),
            (int) $r['version'],
            isset($r['created_by']) ? (string) $r['created_by'] : null,
            new DateTimeImmutable((string) $r['created_at']),
            isset($r['updated_at']) ? new DateTimeImmutable((string) $r['updated_at']) : null,
            isset($r['deleted_at']) ? new DateTimeImmutable((string) $r['deleted_at']) : null,
        );
    }

    /** @return array<string,mixed> */
    private static function hydrate(array $r): array
    {
        return [
__HYDRATE__
        ];
    }

    /**
     * Partial update of editable attributes. Returns the changed fields as [field => [old, new]].
     *
     * @return array<string, array{0:mixed,1:mixed}>
     */
    public function update(array $input): array
    {
        $this->assertEditable();
        $data = {{Entity}}Rules::normalize(array_intersect_key($input, array_flip({{Entity}}Rules::EDITABLE)), false);
        $changes = [];
        foreach ($data as $field => $value) {
            if ($this->values[$field] !== $value) {
                $changes[$field] = [$this->values[$field], $value];
                $this->values[$field] = $value;
            }
        }
        if ($changes !== []) {
            $this->record(self::EVENT_UPDATED, ['changes' => array_map(static fn(array $c) => ['from' => $c[0], 'to' => $c[1]], $changes)]);
        }
        return $changes;
    }
__STATUS_METHODS__
    /** Soft delete (row kept for audit; hidden from every query). */
    public function markDeleted(): void
    {
        $this->assertNotDeleted();
        $this->deletedAt = new DateTimeImmutable();
        $this->record(self::EVENT_DELETED, ['title' => $this->displayTitle()]);
    }

    public function assertEditable(): void
    {
        $this->assertNotDeleted();__TERMINAL__
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /** Display title (first text attribute, otherwise the id). */
    public function displayTitle(): string
    {
        return __TITLE__;
    }

    /** @return list<array{type:string, payload:array<string,mixed>}> */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /** @return array<string,mixed> */
    public function values(): array
    {
        return $this->values;
    }

__GETTERS__

    /** Persistence row (without managed columns: version, timestamps). */
    public function toRow(): array
    {
        return ['id' => $this->id, 'tenant_id' => $this->tenantId] + $this->values + [
            'deleted_at' => $this->deletedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** Public representation (API, audit, events). */
    public function toArray(): array
    {
        return ['id' => $this->id, 'tenant_id' => $this->tenantId] + $this->values + [
            'version' => $this->version,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    private function assertNotDeleted(): void
    {
        if ($this->deletedAt !== null) {
            throw new ConflictException('{{entityLabel}} was deleted.', '{{entity_snake}}_deleted');
        }
    }

    private function record(string $type, array $payload): void
    {
        $this->events[] = ['type' => $type, 'payload' => $payload];
    }
}

PHP, [
    '__LIFECYCLE__' => $status ? "lifecycle ({{Entity}}Status; the terminal state is read-only),\n * " : '',
    '__STATUS_CONST__' => $statusConst,
    '__STATUS_INIT__' => $statusInit,
    '__STATUS_METHODS__' => $statusMethods,
    '__TERMINAL__' => $terminalCheck,
    '__HYDRATE__' => implode("\n", $hydrate),
    '__GETTERS__' => implode("\n\n", $getters),
    '__TITLE__' => $titleExpr,
]);
