<?php

declare(strict_types=1);

use App\Admin\Action\DevTools\GiiController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * 22a · Developer Tools → Enterprise Gii. 404 in production, with flag devtools.gii off or without
 * yiisoft/yii-gii (checked by the controller). Writing files requires a recent step-up authentication.
 */
return [
    Group::create('/devtools/gii')
        ->middleware(RequirePermission::for('devtools.use'))
        ->routes(
            Route::get('')->action([GiiController::class, 'index'])->name('admin.devtools.gii'),
            Route::post('/preview')->action([GiiController::class, 'preview'])->name('admin.devtools.gii.preview'),
            Route::post('/apply')->middleware(RequireRecentAuth::class)->action([GiiController::class, 'apply'])->name('admin.devtools.gii.apply'),
        ),
];
