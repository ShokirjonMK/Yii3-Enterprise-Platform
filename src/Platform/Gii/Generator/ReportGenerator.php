<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Report: background job rendering a CSV (UTF-8 BOM, formula-injection safe) to ObjectStorage — like
 * projects.report.generate — plus the report table migration and the job registration.
 */
final class ReportGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'report';
    }

    public static function getName(): string
    {
        return 'Report';
    }

    public static function getDescription(): string
    {
        return 'Background CSV report job writing to ObjectStorage, report table migration and job registration.';
    }

    public static function requires(): array
    {
        return ['crud'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Infrastructure/Generate' . $m->module . 'ReportJob.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Infrastructure/Generate' . $m->module . 'ReportJob.php') => 'job.php',
            $m->migrationPath('Reports') => 'migration.php',
            'config/common/jobs/' . $m->code . '.php' => '../_shared/jobs.php',
        ];
    }
}
