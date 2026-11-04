<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use App\Shared\Domain\Exception\DomainException;

/**
 * Protocol-level API error with an explicit HTTP status and machine code (e.g. 401 invalid_grant,
 * 403 insufficient_scope, 415 unsupported_media_type, 428 precondition_required). Rendered by
 * DomainErrorMiddleware in JSON mode like any other domain exception.
 */
final class ApiException extends DomainException
{
    /** @param array<string, list<string>> $details */
    public function __construct(
        private readonly int $status,
        private readonly string $code_,
        string $message,
        private readonly array $details_ = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message = 'Authentication is required.', string $code = 'unauthorized'): self
    {
        return new self(401, $code, $message);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->code_;
    }

    public function details(): array
    {
        return $this->details_;
    }
}
