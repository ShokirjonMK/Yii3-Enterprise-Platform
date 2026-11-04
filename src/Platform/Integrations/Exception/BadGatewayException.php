<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 502. */
final class BadGatewayException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
