<?php

declare(strict_types=1);

use App\Admin\Action\Redis\RedisController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 13 · Redis Management — redis.view / redis.manage + step-up re-auth for the cache clear. */
return [
    Group::create('/redis')->middleware(RequirePermission::for('redis.view'))->routes(
        Route::get('')->action([RedisController::class, 'index'])->name('admin.redis'),
        Route::get('/keys')->action([RedisController::class, 'keys'])->name('admin.redis.keys'),
        Route::get('/keys/inspect')->action([RedisController::class, 'key'])->name('admin.redis.key'),
        Route::post('/cache/clear')->middleware(RequirePermission::for('redis.manage'), RequireRecentAuth::class)->action([RedisController::class, 'clearCache'])->name('admin.redis.cache.clear'),
    ),
];
