<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class TooManyRequestsException extends DomainException
{
    public function __construct(string $message = 'Too many requests.', private readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function httpStatus(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'too_many_requests';
    }
}
