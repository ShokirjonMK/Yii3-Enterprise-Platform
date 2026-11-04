<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'not_found';
    }
}
