<?php

declare(strict_types=1);

use App\Admin\Rbac\RbacController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 04 · RBAC & Access Control (dynamic roles and permissions) */
$role = '{role:[a-z][a-z0-9_]{1,63}}';
$perm = '{perm:[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+}';
$rbacManage = RequirePermission::for('rbac.manage');

return [
    Group::create('/rbac')->middleware(RequirePermission::for('rbac.view'))->routes(
        Route::get('')->action([RbacController::class, 'index'])->name('admin.rbac'),
        Route::get('/matrix')->action([RbacController::class, 'matrix'])->name('admin.rbac.matrix'),
        Route::post('/sync')->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'sync'])->name('admin.rbac.sync'),

        Route::post('/roles')->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'createRole'])->name('admin.rbac.roles.create'),
        Route::get("/roles/$role")->action([RbacController::class, 'role'])->name('admin.rbac.role'),
        Route::post("/roles/$role/edit")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'updateRole'])->name('admin.rbac.roles.update'),
        Route::post("/roles/$role/delete")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'deleteRole'])->name('admin.rbac.roles.delete'),
        Route::post("/roles/$role/reset")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'resetRole'])->name('admin.rbac.roles.reset'),
        Route::post("/roles/$role/assign")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'assign'])->name('admin.rbac.assign'),
        Route::post("/roles/$role/revoke")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'revoke'])->name('admin.rbac.revoke'),

        Route::get('/permissions')->action([RbacController::class, 'permissions'])->name('admin.rbac.permissions'),
        Route::post('/permissions')->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'createPermission'])->name('admin.rbac.permissions.create'),
        Route::post("/permissions/$perm/delete")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'deletePermission'])->name('admin.rbac.permissions.delete'),
    ),
];
