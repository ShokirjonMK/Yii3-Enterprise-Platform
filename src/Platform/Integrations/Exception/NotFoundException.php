<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 404 — remote resource does not exist. */
final class NotFoundException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
