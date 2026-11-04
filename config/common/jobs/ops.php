<?php

declare(strict_types=1);

use App\Platform\Backup\BackupJobs;
use App\Platform\Deployments\DeploymentPollJob;
use App\Platform\Monitoring\HealthProbeJob;

/**
 * Operations job handlers (sections 15-20, 24). Schedules for platform.health.probe,
 * platform.backup.run and platform.backup.verify_latest are seeded by PlatformSeeder.
 */
return [
    'platform.health.probe' => HealthProbeJob::class,
    'platform.backup.run' => BackupJobs::class,
    'platform.backup.verify_latest' => BackupJobs::class,
    'platform.backup.verify' => BackupJobs::class,
    'platform.deploy.poll' => DeploymentPollJob::class,
];
