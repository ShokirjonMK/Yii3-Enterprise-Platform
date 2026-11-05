<?php

declare(strict_types=1);

use App\Admin\Storage\StorageController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 14 · Storage & Search — storage.view / storage.manage (+ step-up re-auth for delete). */
return [
    Group::create('/storage')->middleware(RequirePermission::for('storage.view'))->routes(
        Route::get('')->action([StorageController::class, 'index'])->name('admin.storage'),
        Route::get('/search')->action([StorageController::class, 'search'])->name('admin.storage.search'),
        Route::post('/upload')->middleware(RequirePermission::for('storage.manage'))->action([StorageController::class, 'upload'])->name('admin.storage.upload'),
        Route::get('/files/{id:[0-9a-fA-F-]{36}}/download')->action([StorageController::class, 'download'])->name('admin.storage.download'),
        Route::post('/files/{id:[0-9a-fA-F-]{36}}/presign')->action([StorageController::class, 'presign'])->name('admin.storage.presign'),
        Route::post('/files/{id:[0-9a-fA-F-]{36}}/delete')->middleware(RequirePermission::for('storage.manage'), RequireRecentAuth::class)->action([StorageController::class, 'delete'])->name('admin.storage.delete'),
    ),
];
