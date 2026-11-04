<?php

declare(strict_types=1);

use App\Platform\Integrations\AlertNotifier;

/**
 * Integration work package (07-09, 21-23). Everything else (HttpClient, CircuitBreaker, UrlGuard,
 * IntegrationService, WebhookService, WorkflowEngine, ApprovalService, ModuleRegistry, channels) is autowired.
 *
 * @var array $params
 */

return [
    AlertNotifier::class => [
        '__construct()' => ['from' => $params['platform']['mailer']['from']],
    ],
];
