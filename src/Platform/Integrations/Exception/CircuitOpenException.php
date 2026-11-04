<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** The circuit breaker of the integration is open — the call was not attempted. */
final class CircuitOpenException extends IntegrationException
{
    public function __construct(string $integration, private readonly int $retryAfter)
    {
        parent::__construct("Circuit breaker for \"$integration\" is open; retry in {$retryAfter}s.", 'circuit_open', null, $integration);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
