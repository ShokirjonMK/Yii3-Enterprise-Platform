<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

use App\Shared\Domain\Exception\ConflictException;

/**
 * Project lifecycle state machine:
 *
 *   draft ──► active ◄──► on_hold
 *     │         │            │
 *     │         └──► completed ◄┘
 *     │                  │
 *     └──────► archived ◄┘          (archived is terminal)
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Archived = 'archived';

    private const TRANSITIONS = [
        'draft' => ['active', 'archived'],
        'active' => ['on_hold', 'completed'],
        'on_hold' => ['active', 'completed'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return array_map(static fn(string $s) => self::from($s), self::TRANSITIONS[$this->value]);
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    public function assertTransitionTo(self $to): void
    {
        if (!$this->canTransitionTo($to)) {
            throw new ConflictException(
                sprintf('A project cannot move from "%s" to "%s".', $this->value, $to->value),
                'invalid_transition',
            );
        }
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $s) => $s->value, self::cases());
    }
}
