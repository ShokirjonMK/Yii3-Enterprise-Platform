<?php

declare(strict_types=1);

use App\Admin\Action\Dashboard\DashboardController;
use App\Admin\Action\Dashboard\SearchController;
use Yiisoft\Router\Route;

return [
    Route::get('')->action([DashboardController::class, 'index'])->name('admin.dashboard'),
    Route::get('/dashboard/kpis')->action([DashboardController::class, 'kpiFragment'])->name('admin.dashboard.kpis'),
    Route::get('/dashboard/health')->action([DashboardController::class, 'healthFragment'])->name('admin.dashboard.health'),
    Route::get('/search')->action([SearchController::class, 'index'])->name('admin.search'),
];
