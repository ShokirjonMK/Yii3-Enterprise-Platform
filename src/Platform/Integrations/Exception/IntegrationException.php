<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

use RuntimeException;
use Throwable;

/**
 * Base of the typed outbound-HTTP error hierarchy produced by {@see \App\Platform\Integrations\Http\HttpClient}.
 * `errorClass()` is the stable machine name stored in integration_logs.error_class and used as metric label.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorClass = 'error',
        private readonly ?int $statusCode = null,
        private readonly string $integration = '',
        ?Throwable $previous = null,
        private readonly ?string $responseSample = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function integration(): string
    {
        return $this->integration;
    }

    /** Redacted response body sample (≤ 2 KB) when the remote side answered. */
    public function responseSample(): ?string
    {
        return $this->responseSample;
    }

    /** Whether a later attempt may succeed (used by job handlers to choose retry vs permanent failure). */
    public function isRetryable(): bool
    {
        return false;
    }
}
