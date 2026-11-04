<?php

declare(strict_types=1);

use App\Admin\Action\Backups\BackupsController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 19 · Backup & Recovery. A dump contains all data (incl. password hashes): download needs backup.manage. */
$uuid = '{id:[0-9a-fA-F-]{36}}';

return [
    Group::create('/backups')->middleware(RequirePermission::for('backup.view'))->routes(
        Route::get('')->action([BackupsController::class, 'index'])->name('admin.backups'),
        Route::post('/start')->middleware(RequirePermission::for('backup.manage'))->middleware(RequireRecentAuth::class)
            ->action([BackupsController::class, 'start'])->name('admin.backups.start'),
        Route::get('/' . $uuid)->action([BackupsController::class, 'view'])->name('admin.backups.view'),
        Route::post('/' . $uuid . '/verify')->middleware(RequirePermission::for('backup.manage'))->middleware(RequireRecentAuth::class)
            ->action([BackupsController::class, 'verify'])->name('admin.backups.verify'),
        Route::get('/' . $uuid . '/download')->middleware(RequirePermission::for('backup.manage'))
            ->action([BackupsController::class, 'download'])->name('admin.backups.download'),
    ),
];
