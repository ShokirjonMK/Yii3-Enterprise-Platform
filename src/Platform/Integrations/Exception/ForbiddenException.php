<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 403 — credentials valid but not permitted. */
final class ForbiddenException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
