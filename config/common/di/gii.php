<?php

declare(strict_types=1);

use App\Platform\Gii\EnterpriseGii;
use App\Platform\Gii\NextSteps\NextStepsRunner;

/**
 * Enterprise Gii (built on yiisoft/yii-gii, dev dependency). The generator root is the project root;
 * tests use EnterpriseGii::withRoot() with a temporary copy. Nothing is defined when yii-gii is absent.
 * NextStepsRunner ("Next steps" from the admin panel) runs the project's own console in the project root.
 */
$nextSteps = [
    NextStepsRunner::class => [
        '__construct()' => [
            'rootPath' => dirname(__DIR__, 3),
            'migrationPaths' => $params['yiisoft/db-migration']['sourcePaths'] ?? [dirname(__DIR__, 3) . '/migrations'],
        ],
    ],
];

if (!EnterpriseGii::isInstalled()) {
    return $nextSteps;
}

return $nextSteps + [
    EnterpriseGii::class => [
        '__construct()' => [
            'rootPath' => dirname(__DIR__, 3),
        ],
    ],
];
