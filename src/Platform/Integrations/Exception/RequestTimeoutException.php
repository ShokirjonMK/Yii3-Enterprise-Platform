<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 408 or a local connect/total timeout. */
final class RequestTimeoutException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
