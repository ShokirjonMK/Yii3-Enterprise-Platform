<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use App\Shared\Domain\Exception\DomainException;

final class SsoException extends DomainException
{
    public function __construct(string $message, private readonly string $reason = 'sso_error')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
