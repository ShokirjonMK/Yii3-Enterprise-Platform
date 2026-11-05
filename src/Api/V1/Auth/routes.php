<?php

declare(strict_types=1);

use App\Api\V1\Auth\AuthController;
use App\Api\V1\Middleware\BearerAuthentication;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** Token endpoint (public, throttled by AuthenticationService + rate limit) and current principal. */
return [
    Route::post('/auth/token')->action([AuthController::class, 'token'])->name('api.v1.auth.token'),
    Route::post('/auth/refresh')->action([AuthController::class, 'refresh'])->name('api.v1.auth.refresh'),
    Route::post('/auth/revoke')->action([AuthController::class, 'revoke'])->name('api.v1.auth.revoke'),
    Group::create()
        ->middleware(BearerAuthentication::class)
        ->routes(
            Route::get('/me')->action([AuthController::class, 'me'])->name('api.v1.me'),
        ),
];
