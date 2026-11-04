<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** The integration/provider is disabled or misses required configuration. */
final class NotConfiguredException extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
