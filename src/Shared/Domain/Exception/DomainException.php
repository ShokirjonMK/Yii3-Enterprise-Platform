<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use RuntimeException;

/**
 * Base for business-rule violations. Mapped to HTTP status codes by the API/Web error layer;
 * the message is safe to show to the end user (never put secrets or internals in it).
 */
abstract class DomainException extends RuntimeException
{
    abstract public function httpStatus(): int;

    public function errorCode(): string
    {
        return 'domain_error';
    }

    /** @return array<string, list<string>> */
    public function details(): array
    {
        return [];
    }
}
