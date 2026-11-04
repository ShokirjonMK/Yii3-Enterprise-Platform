<?php

declare(strict_types=1);

use App\Api\V1\Action\Auth\AuthController;
use App\Api\V1\Action\Docs\DocsController;
use Yiisoft\Router\Route;

/** Public system endpoints of API v1. */
return [
    Route::get('/health')->action([AuthController::class, 'health'])->name('api.v1.health'),
    Route::get('/openapi.json')->middleware(\App\Api\V1\Middleware\RequireFeature::for('api.docs'))->action([DocsController::class, 'openapi'])->name('api.v1.openapi'),
];
