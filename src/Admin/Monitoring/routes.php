<?php

declare(strict_types=1);

use App\Admin\Monitoring\MonitoringController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 17 · Monitoring & Observability (read-only) */
return [
    Group::create('/monitoring')->middleware(RequirePermission::for('monitoring.view'))->routes(
        Route::get('')->action([MonitoringController::class, 'index'])->name('admin.monitoring'),
        Route::get('/live')->action([MonitoringController::class, 'live'])->name('admin.monitoring.live'),
        Route::get('/logs')->action([App\Admin\Monitoring\LogsController::class, 'index'])->name('admin.monitoring.logs'),
        Route::get('/logs/{id:\d+}')->action([App\Admin\Monitoring\LogsController::class, 'view'])->name('admin.monitoring.logs.view'),
    ),
];
