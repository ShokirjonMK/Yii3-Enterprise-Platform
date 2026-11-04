<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/** Thrown when a critical operation requires a fresh password + MFA confirmation. */
final class ReauthenticationRequiredException extends DomainException
{
    public function __construct(string $message = 'Please confirm your identity to continue.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 428;
    }

    public function errorCode(): string
    {
        return 'reauthentication_required';
    }
}
