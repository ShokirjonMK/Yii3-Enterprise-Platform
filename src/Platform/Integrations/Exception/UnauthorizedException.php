<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** HTTP 401 — credentials missing/invalid. */
final class UnauthorizedException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
