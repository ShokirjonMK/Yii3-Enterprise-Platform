<?php

declare(strict_types=1);

use App\Platform\Maintenance\MaintenanceJobs;

/**
 * Job type → handler map. Modules add files to config/common/jobs/*.php returning the same shape.
 *   'type' => Handler::class
 *   'type' => ['handler' => Handler::class, 'queue' => 'notifications']
 */
$map = [
    'platform.maintenance.partitions' => MaintenanceJobs::class,
    'platform.maintenance.cleanup' => MaintenanceJobs::class,
    'platform.flags.apply_schedule' => MaintenanceJobs::class,
    'platform.queue.reap' => MaintenanceJobs::class,
    'platform.notifications.deliver' => ['handler' => App\Platform\Notifications\DeliverNotificationJob::class, 'queue' => 'notifications'],
];

foreach ([...(glob(__DIR__ . '/jobs/*.php') ?: []), ...(glob(dirname(__DIR__, 2) . '/src/Modules/*/config/jobs.php') ?: [])] as $file) {
    $map = array_merge($map, require $file);
}

return $map;
