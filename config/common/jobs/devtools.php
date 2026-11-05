<?php

declare(strict_types=1);

use App\Platform\Gii\NextSteps\NextStepsJob;

/**
 * Developer tools: Enterprise Gii "Next steps" runs (migrate:up, rbac:sync, Kafka topics).
 */
return [
    'devtools.gii.next_steps' => NextStepsJob::class,
];
