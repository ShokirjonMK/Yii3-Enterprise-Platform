<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/**
 * HTTP 429 from the remote side (Retry-After honoured), or the local per-integration rate limit
 * (errorClass "rate_limited_local") was exceeded before sending.
 */
final class RateLimitedException extends IntegrationException
{
    public function __construct(string $message, private readonly int $retryAfter, string $errorClass = 'rate_limited', ?int $statusCode = 429, string $integration = '', ?string $responseSample = null)
    {
        parent::__construct($message, $errorClass, $statusCode, $integration, null, $responseSample);
    }

    /** Seconds until a retry makes sense. */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
