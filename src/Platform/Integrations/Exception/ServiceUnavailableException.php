<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 503. */
final class ServiceUnavailableException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
