<?php

declare(strict_types=1);

use App\Platform\Gii\Console\GiiModuleCommand;
use App\Platform\Gii\EnterpriseGii;

/**
 * Enterprise Gii console command. yiisoft/yii-gii is a dev dependency: without it (production images)
 * the command is not registered at all.
 */
return EnterpriseGii::isInstalled() ? ['gii:module' => GiiModuleCommand::class] : [];
