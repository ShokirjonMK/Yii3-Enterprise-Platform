<?php

declare(strict_types=1);

use App\Admin\Databases\DatabasesController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * Databases — named connections to any engine. databases.view (list/status/objects), databases.manage + step-up
 * (add/edit/enable/delete), databases.query + step-up (console; write mode additionally databases.write, checked in
 * the controller and QueryConsole).
 */
$n = '{name:[a-z][a-z0-9_]{1,63}}';
$manage = RequirePermission::for('databases.manage');

return [
    Group::create('/databases')->middleware(RequirePermission::for('databases.view'))->routes(
        Route::get('')->action([DatabasesController::class, 'index'])->name('admin.databases'),
        Route::get('/new')->middleware($manage)->action([DatabasesController::class, 'create'])->name('admin.databases.create'),
        Route::post('')->middleware($manage, RequireRecentAuth::class)->action([DatabasesController::class, 'store'])->name('admin.databases.store'),
        Route::post('/test')->middleware($manage)->action([DatabasesController::class, 'testDraft'])->name('admin.databases.test_draft'),
        Route::get("/$n")->action([DatabasesController::class, 'show'])->name('admin.databases.show'),
        Route::get("/$n/status")->action([DatabasesController::class, 'status'])->name('admin.databases.status'),
        Route::post("/$n/test")->action([DatabasesController::class, 'test'])->name('admin.databases.test'),
        Route::get("/$n/edit")->middleware($manage)->action([DatabasesController::class, 'edit'])->name('admin.databases.edit'),
        Route::post("/$n")->middleware($manage, RequireRecentAuth::class)->action([DatabasesController::class, 'update'])->name('admin.databases.update'),
        Route::post("/$n/toggle")->middleware($manage, RequireRecentAuth::class)->action([DatabasesController::class, 'toggle'])->name('admin.databases.toggle'),
        Route::post("/$n/delete")->middleware($manage, RequireRecentAuth::class)->action([DatabasesController::class, 'delete'])->name('admin.databases.delete'),
        Route::get("/$n/console")->middleware(RequirePermission::for('databases.query'))->action([DatabasesController::class, 'console'])->name('admin.databases.console'),
        Route::post("/$n/console")->middleware(RequirePermission::for('databases.query'), RequireRecentAuth::class)->action([DatabasesController::class, 'run'])->name('admin.databases.console.run'),
    ),
];
