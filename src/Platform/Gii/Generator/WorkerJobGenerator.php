<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Worker Job: queue handler purging soft-deleted rows after a retention period (batched, cancellable,
 * audited) registered in src/Modules/<Module>/config/jobs.php.
 */
final class WorkerJobGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'job';
    }

    public static function getName(): string
    {
        return 'Worker Job';
    }

    public static function getDescription(): string
    {
        return 'Queue job handler (purges soft-deleted rows after a retention period) registered in src/Modules/<Module>/config/jobs.php.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Infrastructure/PurgeDeleted' . $m->module . 'Job.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Infrastructure/PurgeDeleted' . $m->module . 'Job.php') => 'job.php',
            $m->modulePath('config/jobs.php') => '../_shared/jobs.php',
        ];
    }
}
