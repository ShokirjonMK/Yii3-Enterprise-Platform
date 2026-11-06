<?php

declare(strict_types=1);

use App\Modules\MultiDb\Console;

/** Console commands of the Multi-DB demo module (merged into config/console/commands.php). */
return [
    'multidb:install' => Console\InstallCommand::class,
    'multidb:crud' => Console\CrudCommand::class,
];
