<?php

declare(strict_types=1);

use App\Admin\Jobs\JobsController;
use App\Admin\Jobs\SchedulesController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 10 · Jobs & Scheduler — jobs.view (read) / jobs.manage (operator actions). Closure keeps helper variables local. */
return (static function (): array {
    $manage = RequirePermission::for('jobs.manage');
    $uuid = '{id:[0-9a-fA-F-]{36}}';

    return [
        Group::create('/jobs')->middleware(RequirePermission::for('jobs.view'))->routes(
            Route::get('')->action([JobsController::class, 'index'])->name('admin.jobs'),
            Route::get('/failed')->action([JobsController::class, 'failed'])->name('admin.jobs.failed'),
            Route::get('/workers')->action([JobsController::class, 'workers'])->name('admin.jobs.workers'),
            Route::post('/retry-bulk')->middleware($manage)->action([JobsController::class, 'retryBulk'])->name('admin.jobs.retry_bulk'),
            Route::post('/queues/{queue:[a-z0-9_-]{1,64}}/pause')->middleware($manage)->action([JobsController::class, 'pauseQueue'])->name('admin.jobs.queue.pause'),
            Route::get('/schedules')->action([SchedulesController::class, 'index'])->name('admin.jobs.schedules'),
            Route::get('/schedules/preview')->action([SchedulesController::class, 'preview'])->name('admin.jobs.schedules.preview'),
            Route::get('/schedules/new')->middleware($manage)->action([SchedulesController::class, 'create'])->name('admin.jobs.schedules.create'),
            Route::post('/schedules/new')->middleware($manage)->action([SchedulesController::class, 'store'])->name('admin.jobs.schedules.store'),
            Route::get('/schedules/' . $uuid . '/edit')->middleware($manage)->action([SchedulesController::class, 'edit'])->name('admin.jobs.schedules.edit'),
            Route::post('/schedules/' . $uuid . '/edit')->middleware($manage)->action([SchedulesController::class, 'update'])->name('admin.jobs.schedules.update'),
            Route::post('/schedules/' . $uuid . '/toggle')->middleware($manage)->action([SchedulesController::class, 'toggle'])->name('admin.jobs.schedules.toggle'),
            Route::post('/schedules/' . $uuid . '/run')->middleware($manage)->action([SchedulesController::class, 'runNow'])->name('admin.jobs.schedules.run'),
            Route::get('/' . $uuid)->action([JobsController::class, 'view'])->name('admin.jobs.view'),
            Route::post('/' . $uuid . '/retry')->middleware($manage)->action([JobsController::class, 'retry'])->name('admin.jobs.retry'),
            Route::post('/' . $uuid . '/cancel')->middleware($manage)->action([JobsController::class, 'cancel'])->name('admin.jobs.cancel'),
        ),
    ];
})();
