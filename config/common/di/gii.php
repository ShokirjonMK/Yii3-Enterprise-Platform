<?php

declare(strict_types=1);

use App\Platform\Gii\EnterpriseGii;

/**
 * Enterprise Gii (built on yiisoft/yii-gii, dev dependency). The generator root is the project root;
 * tests use EnterpriseGii::withRoot() with a temporary copy. Nothing is defined when yii-gii is absent.
 */
if (!EnterpriseGii::isInstalled()) {
    return [];
}

return [
    EnterpriseGii::class => [
        '__construct()' => [
            'rootPath' => dirname(__DIR__, 3),
        ],
    ],
];
