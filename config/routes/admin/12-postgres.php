<?php

declare(strict_types=1);

use App\Admin\Action\Postgres\PostgresController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 12 · PostgreSQL Management — db.view (diagnostics) / db.manage + step-up re-auth (safe maintenance). */
return [
    Group::create('/postgres')->middleware(RequirePermission::for('db.view'))->routes(
        Route::get('')->action([PostgresController::class, 'index'])->name('admin.postgres'),
        Route::get('/activity')->action([PostgresController::class, 'activity'])->name('admin.postgres.activity'),
        Route::get('/queries')->action([PostgresController::class, 'queries'])->name('admin.postgres.queries'),
        Route::get('/storage')->action([PostgresController::class, 'storage'])->name('admin.postgres.storage'),
        Route::get('/replication')->action([PostgresController::class, 'replication'])->name('admin.postgres.replication'),
        Route::get('/partitions')->action([PostgresController::class, 'partitions'])->name('admin.postgres.partitions'),
        Route::post('/maintenance')->middleware(RequirePermission::for('db.manage'), RequireRecentAuth::class)->action([PostgresController::class, 'maintain'])->name('admin.postgres.maintain'),
        Route::post('/cancel')->middleware(RequirePermission::for('db.manage'), RequireRecentAuth::class)->action([PostgresController::class, 'cancel'])->name('admin.postgres.cancel'),
        Route::post('/partitions/run')->middleware(RequirePermission::for('db.manage'), RequireRecentAuth::class)->action([PostgresController::class, 'runPartitions'])->name('admin.postgres.partitions.run'),
    ),
];
