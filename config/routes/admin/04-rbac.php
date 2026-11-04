<?php

declare(strict_types=1);

use App\Admin\Action\Rbac\RbacController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 04 · RBAC & Access Control */
$role = '{role:[a-z][a-z0-9_]{1,63}}';
$rbacManage = RequirePermission::for('rbac.manage');

return [
    Group::create('/rbac')->middleware(RequirePermission::for('rbac.view'))->routes(
        Route::get('')->action([RbacController::class, 'index'])->name('admin.rbac'),
        Route::get('/matrix')->action([RbacController::class, 'matrix'])->name('admin.rbac.matrix'),
        Route::post('/sync')->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'sync'])->name('admin.rbac.sync'),
        Route::get("/roles/$role")->action([RbacController::class, 'role'])->name('admin.rbac.role'),
        Route::post("/roles/$role/assign")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'assign'])->name('admin.rbac.assign'),
        Route::post("/roles/$role/revoke")->middleware($rbacManage, RequireRecentAuth::class)->action([RbacController::class, 'revoke'])->name('admin.rbac.revoke'),
    ),
];
