<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** Other HTTP 4xx (bad request, conflict, validation error on the remote side). */
final class ClientErrorException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
