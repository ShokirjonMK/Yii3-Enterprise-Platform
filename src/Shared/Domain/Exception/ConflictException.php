<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Optimistic-lock failures, duplicate keys, forbidden state transitions. */
final class ConflictException extends DomainException
{
    public function __construct(string $message = 'Conflict.', private readonly string $conflictCode = 'conflict')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return $this->conflictCode;
    }
}
