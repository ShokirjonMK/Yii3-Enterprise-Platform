<?php

declare(strict_types=1);

use App\Admin\Action\Tenants\TenantsController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 05 · Multi-Tenancy */
$tenant = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';
$tenantsManage = RequirePermission::for('tenants.manage');

return [
    Group::create('/tenants')->middleware(RequirePermission::for('tenants.view'))->routes(
        Route::get('')->action([TenantsController::class, 'index'])->name('admin.tenants'),
        Route::get('/create')->middleware($tenantsManage)->action([TenantsController::class, 'createForm'])->name('admin.tenants.create'),
        Route::post('/create')->middleware($tenantsManage)->action([TenantsController::class, 'store'])->name('admin.tenants.store'),
        Route::get("/$tenant")->action([TenantsController::class, 'view'])->name('admin.tenants.view'),
        Route::get("/$tenant/edit")->middleware($tenantsManage)->action([TenantsController::class, 'editForm'])->name('admin.tenants.edit'),
        Route::post("/$tenant/edit")->middleware($tenantsManage)->action([TenantsController::class, 'update'])->name('admin.tenants.update'),
        Route::post("/$tenant/status")->middleware($tenantsManage, RequireRecentAuth::class)->action([TenantsController::class, 'status'])->name('admin.tenants.status'),
        Route::post("/$tenant/flags")->middleware($tenantsManage)->action([TenantsController::class, 'flag'])->name('admin.tenants.flags'),
        Route::post("/$tenant/delete")->middleware($tenantsManage, RequireRecentAuth::class)->action([TenantsController::class, 'delete'])->name('admin.tenants.delete'),
    ),
];
