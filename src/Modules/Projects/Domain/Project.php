<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Project aggregate root. Enforces its own invariants (valid attributes, date order, lifecycle
 * transitions, archived projects are read-only) and records domain events for the outbox.
 */
final class Project
{
    /** @var list<ProjectEvent> */
    private array $events = [];

    /** @param list<string> $tags */
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        private string $code,
        private string $name,
        private string $description,
        private ProjectStatus $status,
        private ProjectPriority $priority,
        private string $ownerId,
        private ?string $budget,
        private ?string $startsOn,
        private ?string $dueOn,
        private array $tags,
        private int $version,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $archivedAt,
    ) {}

    /**
     * @param array<string,mixed> $input raw input (validated here)
     */
    public static function create(string $id, string $tenantId, array $input, string $ownerId, ?string $createdBy): self
    {
        $d = ProjectRules::normalize($input, true);
        ProjectRules::assertDates($d['starts_on'] ?? null, $d['due_on'] ?? null);
        $p = new self(
            $id,
            $tenantId,
            $d['code'],
            $d['name'],
            $d['description'],
            ProjectStatus::Draft,
            ProjectPriority::from($d['priority']),
            $ownerId,
            $d['budget'] ?? null,
            $d['starts_on'] ?? null,
            $d['due_on'] ?? null,
            $d['tags'],
            1,
            $createdBy,
            new DateTimeImmutable(),
            null,
            null,
        );
        $p->events[] = new ProjectEvent(ProjectEvent::CREATED, $id, $p->toArray());
        return $p;
    }

    public static function fromRow(array $r): self
    {
        $tags = $r['tags'] ?? '[]';
        return new self(
            (string) $r['id'],
            (string) $r['tenant_id'],
            (string) $r['code'],
            (string) $r['name'],
            (string) ($r['description'] ?? ''),
            ProjectStatus::from((string) $r['status']),
            ProjectPriority::from((string) $r['priority']),
            (string) $r['owner_id'],
            $r['budget'] === null ? null : number_format((float) $r['budget'], 2, '.', ''),
            $r['starts_on'] === null ? null : substr((string) $r['starts_on'], 0, 10),
            $r['due_on'] === null ? null : substr((string) $r['due_on'], 0, 10),
            is_array($tags) ? $tags : (json_decode((string) $tags, true) ?: []),
            (int) $r['version'],
            $r['created_by'] ?? null,
            new DateTimeImmutable((string) $r['created_at']),
            isset($r['updated_at']) ? new DateTimeImmutable((string) $r['updated_at']) : null,
            isset($r['archived_at']) ? new DateTimeImmutable((string) $r['archived_at']) : null,
        );
    }

    /**
     * Applies a partial update. Returns the changed fields as [field => [old, new]].
     *
     * @return array<string, array{0:mixed,1:mixed}>
     */
    public function update(array $input): array
    {
        $this->assertEditable();
        $d = ProjectRules::normalize(array_intersect_key($input, array_flip(ProjectRules::EDITABLE)), false);
        if (array_key_exists('owner_id', $d) && $d['owner_id'] === null) {
            throw ValidationException::single('owner_id', 'A project must have an owner.');
        }
        ProjectRules::assertDates(
            array_key_exists('starts_on', $d) ? $d['starts_on'] : $this->startsOn,
            array_key_exists('due_on', $d) ? $d['due_on'] : $this->dueOn,
        );
        $before = $this->toArray();
        foreach ($d as $field => $value) {
            match ($field) {
                'code' => $this->code = $value,
                'name' => $this->name = $value,
                'description' => $this->description = $value,
                'priority' => $this->priority = ProjectPriority::from($value),
                'owner_id' => $this->ownerId = $value,
                'budget' => $this->budget = $value,
                'starts_on' => $this->startsOn = $value,
                'due_on' => $this->dueOn = $value,
                'tags' => $this->tags = $value,
            };
        }
        $after = $this->toArray();
        $changes = [];
        foreach (array_keys($d) as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = [$before[$field], $after[$field]];
            }
        }
        if ($changes !== []) {
            $this->events[] = new ProjectEvent(ProjectEvent::UPDATED, $this->id, ['changes' => array_map(static fn($c) => ['from' => $c[0], 'to' => $c[1]], $changes)]);
        }
        return $changes;
    }

    public function changeStatus(ProjectStatus $to, string $comment = ''): ProjectStatus
    {
        $from = $this->status;
        $from->assertTransitionTo($to);
        $this->status = $to;
        if ($to === ProjectStatus::Archived) {
            $this->archivedAt = new DateTimeImmutable();
        }
        $payload = ['from' => $from->value, 'to' => $to->value, 'code' => $this->code, 'name' => $this->name, 'comment' => mb_substr($comment, 0, 500)];
        $this->events[] = new ProjectEvent($to === ProjectStatus::Archived ? ProjectEvent::ARCHIVED : ProjectEvent::STATUS_CHANGED, $this->id, $payload);
        return $from;
    }

    public function assertEditable(): void
    {
        if ($this->status->isTerminal()) {
            throw new ConflictException('Archived projects are read-only.', 'project_archived');
        }
    }

    /** @return list<ProjectEvent> */
    public function pullEvents(): array
    {
        $e = $this->events;
        $this->events = [];
        return $e;
    }

    public function markPersisted(int $version): void
    {
        $this->version = $version;
    }

    public function code(): string
    {
        return $this->code;
    }
    public function name(): string
    {
        return $this->name;
    }
    public function description(): string
    {
        return $this->description;
    }
    public function status(): ProjectStatus
    {
        return $this->status;
    }
    public function priority(): ProjectPriority
    {
        return $this->priority;
    }
    public function ownerId(): string
    {
        return $this->ownerId;
    }
    public function budget(): ?string
    {
        return $this->budget;
    }
    public function startsOn(): ?string
    {
        return $this->startsOn;
    }
    public function dueOn(): ?string
    {
        return $this->dueOn;
    }
    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }
    public function version(): int
    {
        return $this->version;
    }
    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function archivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /** Persistence row (without generated/managed columns). */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'owner_id' => $this->ownerId,
            'budget' => $this->budget,
            'starts_on' => $this->startsOn,
            'due_on' => $this->dueOn,
            'tags' => json_encode(array_values($this->tags)),
            'archived_at' => $this->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** Public representation (API, audit, events). */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'owner_id' => $this->ownerId,
            'budget' => $this->budget,
            'starts_on' => $this->startsOn,
            'due_on' => $this->dueOn,
            'tags' => array_values($this->tags),
            'version' => $this->version,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
            'archived_at' => $this->archivedAt?->format(DATE_ATOM),
        ];
    }
}
