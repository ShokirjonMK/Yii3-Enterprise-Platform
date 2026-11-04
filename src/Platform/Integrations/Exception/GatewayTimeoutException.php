<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 504. */
final class GatewayTimeoutException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
