<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Exception;

/**
 * Transport failure without an HTTP response. errorClass(): "dns" (name resolution), "tls" (handshake /
 * certificate), "connect" (refused/unreachable) or "network" (reset, other cURL errors).
 */
final class NetworkException extends IntegrationException
{
    public function isRetryable(): bool
    {
        // a TLS/certificate problem is a configuration error — retrying will not help
        return $this->errorClass() !== 'tls';
    }
}
