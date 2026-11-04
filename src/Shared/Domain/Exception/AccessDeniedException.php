<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class AccessDeniedException extends DomainException
{
    public function __construct(string $message = 'Access denied.', private readonly ?string $permission = null)
    {
        parent::__construct($message);
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }
}
