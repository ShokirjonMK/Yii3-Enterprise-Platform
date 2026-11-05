<?php

declare(strict_types=1);

use App\Admin\DevTools\DevToolsController;
use App\Admin\DevTools\GiiController;
use App\Admin\DevTools\GiiNextStepsController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * 22 · Developer Tools and Enterprise Gii. The controllers return 404 in production or when flag devtools.gii is off
 * (Gii also without yiisoft/yii-gii). Writing files and running the next steps require a recent step-up authentication.
 */
return [
    Route::get('/devtools')->middleware(RequirePermission::for('devtools.use'))->action([DevToolsController::class, 'index'])->name('admin.devtools'),

    Group::create('/devtools/gii')
        ->middleware(RequirePermission::for('devtools.use'))
        ->routes(
            Route::get('')->action([GiiController::class, 'index'])->name('admin.devtools.gii'),
            Route::post('/preview')->action([GiiController::class, 'preview'])->name('admin.devtools.gii.preview'),
            Route::post('/apply')->middleware(RequireRecentAuth::class)->action([GiiController::class, 'apply'])->name('admin.devtools.gii.apply'),
            Route::get('/next-steps')->action([GiiNextStepsController::class, 'index'])->name('admin.devtools.gii.steps'),
            Route::post('/next-steps')->middleware(RequireRecentAuth::class)->action([GiiNextStepsController::class, 'run'])->name('admin.devtools.gii.steps.run'),
            Route::get('/runs/{id}')->action([GiiNextStepsController::class, 'view'])->name('admin.devtools.gii.runs.view'),
            Route::get('/runs/{id}/live')->action([GiiNextStepsController::class, 'live'])->name('admin.devtools.gii.runs.live'),
        ),
];
