<?php

declare(strict_types=1);

use App\Api\V1\Auth\AuthController;
use App\Api\V1\System\DocsController;
use Yiisoft\Router\Route;

/** System endpoints of API v1: health is public, the OpenAPI document is private. */
return [
    Route::get('/health')->action([AuthController::class, 'health'])->name('api.v1.health'),
    // private like /api/docs: reads the browser session (signed-in user with api.docs.view or an unlocked docs password)
    Route::get('/openapi.json')->middleware(\App\Api\V1\Middleware\RequireFeature::for('api.docs'), \Yiisoft\Session\SessionMiddleware::class, \App\Web\Middleware\AuthContextMiddleware::class)->action([DocsController::class, 'openapi'])->name('api.v1.openapi'),
];
