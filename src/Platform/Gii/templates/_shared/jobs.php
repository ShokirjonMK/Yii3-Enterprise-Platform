<?php

declare(strict_types=1);

/**
 * Template: job registry (src/Modules/<Module>/config/jobs.php) — shared by the Worker Job and Report generators.
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$uses = [];
$lines = [];
if ($m->has('job')) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\PurgeDeleted{{Module}}Job;';
    $lines[] = "    '" . $m->cleanupJob() . "' => ['handler' => PurgeDeleted{{Module}}Job::class, 'queue' => 'default'],";
}
if ($m->has('report')) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\Generate{{Module}}ReportJob;';
    $lines[] = "    '" . $m->reportJob() . "' => ['handler' => Generate{{Module}}ReportJob::class, 'queue' => 'default'],";
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

__USES__

/** {{label}} module job types (type => handler + queue). */
return [
__LINES__
];

PHP, ['__USES__' => implode("\n", $uses), '__LINES__' => implode("\n", $lines)]);
