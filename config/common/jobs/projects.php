<?php

declare(strict_types=1);

use App\Modules\Projects\Infrastructure\GenerateProjectsReportJob;

return [
    'projects.report.generate' => ['handler' => GenerateProjectsReportJob::class, 'queue' => 'default'],
];
