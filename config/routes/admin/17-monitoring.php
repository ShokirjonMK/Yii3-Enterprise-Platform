<?php

declare(strict_types=1);

use App\Admin\Action\Monitoring\MonitoringController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 17 · Monitoring & Observability (read-only) */
return [
    Group::create('/monitoring')->middleware(RequirePermission::for('monitoring.view'))->routes(
        Route::get('')->action([MonitoringController::class, 'index'])->name('admin.monitoring'),
        Route::get('/live')->action([MonitoringController::class, 'live'])->name('admin.monitoring.live'),
    ),
];
