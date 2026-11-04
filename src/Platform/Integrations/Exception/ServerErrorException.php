<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 500 (and other 5xx without a dedicated class). */
final class ServerErrorException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
