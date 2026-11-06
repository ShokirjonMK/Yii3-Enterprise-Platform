<?php

declare(strict_types=1);

use App\Admin\Api\ApiController;
use App\Admin\Api\DocsAccessController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 06 · API Management */
$client = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';
$apiManage = RequirePermission::for('api.manage');

return [
    Group::create('/api')->middleware(RequirePermission::for('api.view'))->routes(
        Route::get('')->action([ApiController::class, 'index'])->name('admin.api'),
        // Docs access: list for api.view, issue/revoke passwords for api.docs.manage (superadmin) with step-up
        Route::get('/docs')->action([DocsAccessController::class, 'index'])->name('admin.api.docs'),
        Route::post('/docs')->middleware(RequirePermission::for('api.docs.manage'), RequireRecentAuth::class)->action([DocsAccessController::class, 'issue'])->name('admin.api.docs.issue'),
        Route::post("/docs/$client/revoke")->middleware(RequirePermission::for('api.docs.manage'))->action([DocsAccessController::class, 'revoke'])->name('admin.api.docs.revoke'),
        Route::get('/tokens')->action([ApiController::class, 'tokens'])->name('admin.api.tokens'),
        Route::post("/tokens/$client/revoke")->middleware($apiManage)->action([ApiController::class, 'revokeToken'])->name('admin.api.tokens.revoke'),
        Route::get('/clients/create')->middleware($apiManage)->action([ApiController::class, 'createForm'])->name('admin.api.clients.create'),
        Route::post('/clients/create')->middleware($apiManage)->action([ApiController::class, 'store'])->name('admin.api.clients.store'),
        Route::get("/clients/$client")->action([ApiController::class, 'view'])->name('admin.api.clients.view'),
        Route::get("/clients/$client/edit")->middleware($apiManage)->action([ApiController::class, 'editForm'])->name('admin.api.clients.edit'),
        Route::post("/clients/$client/edit")->middleware($apiManage)->action([ApiController::class, 'update'])->name('admin.api.clients.update'),
        Route::post("/clients/$client/enable")->middleware($apiManage)->action([ApiController::class, 'enable'])->name('admin.api.clients.enable'),
        Route::post("/clients/$client/disable")->middleware($apiManage)->action([ApiController::class, 'disable'])->name('admin.api.clients.disable'),
        Route::post("/clients/$client/rotate")->middleware($apiManage, RequireRecentAuth::class)->action([ApiController::class, 'rotate'])->name('admin.api.clients.rotate'),
        Route::post("/clients/$client/delete")->middleware($apiManage, RequireRecentAuth::class)->action([ApiController::class, 'delete'])->name('admin.api.clients.delete'),
    ),
];
