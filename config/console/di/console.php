<?php

declare(strict_types=1);

use App\Console\Command\InstallCommand;

/** @var array $params */

return [
    InstallCommand::class => [
        '__construct()' => ['platform' => $params['platform']],
    ],
];
