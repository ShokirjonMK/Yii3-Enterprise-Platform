<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/** Destination rejected by {@see \App\Platform\Security\UrlGuard} (private network, metadata, bad scheme). */
final class SsrfBlockedException extends IntegrationException
{
    public function __construct(string $message, string $errorClass = 'ssrf_blocked')
    {
        parent::__construct($message, $errorClass);
    }
}
