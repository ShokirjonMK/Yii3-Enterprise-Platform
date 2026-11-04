<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use RuntimeException;

/**
 * Failure of an external operations API (Kubernetes, GitLab, Prometheus, Alertmanager, Telegram).
 * Messages are safe to show to operators: they never contain credentials.
 */
final class ExternalServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        string $message,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
