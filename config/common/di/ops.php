<?php

declare(strict_types=1);

use App\Platform\Backup\BackupService;
use App\Platform\Incidents\IncidentService;
use App\Platform\Monitoring\SecurityPosture;

/**
 * Operations control center (sections 15-20, 24). Only scalar/config parameters are defined here;
 * everything else is autowired.
 *
 * @var array $params
 */

$platform = $params['platform'];

return [
    BackupService::class => [
        '__construct()' => [
            'dbConfig' => $platform['db'],
            's3Config' => $platform['s3'],
            'timeoutSeconds' => (int) (getenv('BACKUP_TIMEOUT_SECONDS') ?: 3600),
        ],
    ],
    IncidentService::class => [
        '__construct()' => ['baseUrl' => $platform['url']],
    ],
    SecurityPosture::class => [
        '__construct()' => [
            'platform' => [
                'url' => $platform['url'],
                'secret' => $platform['secret'],
                'metricsToken' => $platform['observability']['metricsToken'] ?? null,
                'csp' => $platform['security']['csp'] ?? [],
                'privilegedRoles' => $platform['security']['mfa']['enforceForRoles'] ?? [],
            ],
        ],
    ],
];
