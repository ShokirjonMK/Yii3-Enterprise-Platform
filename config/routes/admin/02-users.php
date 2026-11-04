<?php

declare(strict_types=1);

use App\Admin\Action\Organizations\OrganizationsController;
use App\Admin\Action\Users\UsersController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 02 · Users & Organizations */
$uuid = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';
$manage = RequirePermission::for('users.manage');

return [
    Group::create('/users')->middleware(RequirePermission::for('users.view'))->routes(
        Route::get('')->action([UsersController::class, 'index'])->name('admin.users'),
        Route::get('/create')->middleware($manage)->action([UsersController::class, 'createForm'])->name('admin.users.create'),
        Route::post('/create')->middleware($manage)->action([UsersController::class, 'store'])->name('admin.users.store'),
        Route::get("/$uuid")->action([UsersController::class, 'view'])->name('admin.users.view'),
        Route::get("/$uuid/edit")->middleware($manage)->action([UsersController::class, 'editForm'])->name('admin.users.edit'),
        Route::post("/$uuid/edit")->middleware($manage)->action([UsersController::class, 'update'])->name('admin.users.update'),
        Route::post("/$uuid/block")->middleware($manage)->action([UsersController::class, 'block'])->name('admin.users.block'),
        Route::post("/$uuid/unblock")->middleware($manage)->action([UsersController::class, 'unblock'])->name('admin.users.unblock'),
        Route::post("/$uuid/unlock")->middleware($manage)->action([UsersController::class, 'unlock'])->name('admin.users.unlock'),
        Route::post("/$uuid/archive")->middleware($manage, RequireRecentAuth::class)->action([UsersController::class, 'archive'])->name('admin.users.archive'),
        Route::post("/$uuid/reset-password")->middleware($manage, RequireRecentAuth::class)->action([UsersController::class, 'resetPassword'])->name('admin.users.reset-password'),
        Route::post("/$uuid/roles")->middleware(RequirePermission::for('rbac.manage'), RequireRecentAuth::class)->action([UsersController::class, 'roles'])->name('admin.users.roles'),
        Route::post("/$uuid/sessions/revoke-all")->middleware(RequirePermission::for('sessions.revoke'))->action([UsersController::class, 'revokeAllSessions'])->name('admin.users.sessions.revoke-all'),
        Route::post("/$uuid/sessions/{sid:[0-9a-f]{64}}/revoke")->middleware(RequirePermission::for('sessions.revoke'))->action([UsersController::class, 'revokeSession'])->name('admin.users.sessions.revoke'),
        Route::post("/$uuid/identities/{iid:[0-9a-f-]{36}}/unlink")->middleware($manage, RequireRecentAuth::class)->action([UsersController::class, 'unlinkIdentity'])->name('admin.users.identities.unlink'),
    ),
    Group::create('/organizations')->middleware(RequirePermission::for('users.view'))->routes(
        Route::get('')->action([OrganizationsController::class, 'index'])->name('admin.organizations'),
        Route::get('/create')->middleware($manage)->action([OrganizationsController::class, 'createForm'])->name('admin.organizations.create'),
        Route::post('/create')->middleware($manage)->action([OrganizationsController::class, 'store'])->name('admin.organizations.store'),
        Route::get("/$uuid/edit")->middleware($manage)->action([OrganizationsController::class, 'editForm'])->name('admin.organizations.edit'),
        Route::post("/$uuid/edit")->middleware($manage)->action([OrganizationsController::class, 'update'])->name('admin.organizations.update'),
        Route::post("/$uuid/delete")->middleware($manage)->action([OrganizationsController::class, 'delete'])->name('admin.organizations.delete'),
    ),
];
