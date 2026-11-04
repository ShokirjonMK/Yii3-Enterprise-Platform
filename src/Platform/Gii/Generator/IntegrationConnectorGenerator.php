<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Integration Connector: outbound connector through the platform HttpClient (IntegrationService::call —
 * SSRF guard, timeouts, retries with backoff, circuit breaker, request logging) plus a migration that
 * registers the integration row (disabled until an operator configures it → "Not configured").
 */
final class IntegrationConnectorGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'connector';
    }

    public static function getName(): string
    {
        return 'Integration Connector';
    }

    public static function getDescription(): string
    {
        return 'Outbound connector using the platform HttpClient (timeout/retry/circuit breaker from the integrations row) and a migration registering the integration.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Infrastructure/' . $m->module . 'Connector.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Infrastructure/' . $m->module . 'Connector.php') => 'connector.php',
            $m->migrationPath('Integration') => 'migration.php',
        ];
    }
}
