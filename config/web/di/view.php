<?php

declare(strict_types=1);

use App\Shared\Infrastructure\View\PlatformViewInjection;

/** @var array $params */

return [
    PlatformViewInjection::class => [
        '__construct()' => ['platform' => $params['platform']],
    ],
];
