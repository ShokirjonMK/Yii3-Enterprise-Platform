<?php

declare(strict_types=1);

use App\Admin\Action\Authentication\AuthenticationController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 03 · Authentication (login methods, SSO providers) */
$provider = '{code:[a-z0-9][a-z0-9_-]{1,63}}';
$authManage = RequirePermission::for('auth.manage');

return [
    Group::create('/auth')->middleware(RequirePermission::for('auth.view'))->routes(
        Route::get('')->action([AuthenticationController::class, 'index'])->name('admin.auth'),
        Route::post('/methods/{method:auth\.(?:local_login|sso)}')->middleware($authManage, RequireRecentAuth::class)
            ->action([AuthenticationController::class, 'setMethod'])->name('admin.auth.method'),
        Route::get('/new-provider')->middleware($authManage)->action([AuthenticationController::class, 'createForm'])->name('admin.auth.providers.create'),
        Route::post('/new-provider')->middleware($authManage, RequireRecentAuth::class)->action([AuthenticationController::class, 'store'])->name('admin.auth.providers.store'),
        Route::get("/providers/$provider")->action([AuthenticationController::class, 'view'])->name('admin.auth.providers.view'),
        Route::get("/providers/$provider/edit")->middleware($authManage)->action([AuthenticationController::class, 'editForm'])->name('admin.auth.providers.edit'),
        Route::post("/providers/$provider/edit")->middleware($authManage, RequireRecentAuth::class)->action([AuthenticationController::class, 'update'])->name('admin.auth.providers.update'),
        Route::post("/providers/$provider/enable")->middleware($authManage, RequireRecentAuth::class)->action([AuthenticationController::class, 'enable'])->name('admin.auth.providers.enable'),
        Route::post("/providers/$provider/disable")->middleware($authManage, RequireRecentAuth::class)->action([AuthenticationController::class, 'disable'])->name('admin.auth.providers.disable'),
        Route::post("/providers/$provider/test")->middleware($authManage)->action([AuthenticationController::class, 'test'])->name('admin.auth.providers.test'),
        Route::post("/providers/$provider/delete")->middleware($authManage, RequireRecentAuth::class)->action([AuthenticationController::class, 'delete'])->name('admin.auth.providers.delete'),
    ),
];
