<?php

declare(strict_types=1);

use App\Admin\Integrations\IntegrationsController as I;
use App\Admin\Integrations\WebhooksController as W;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$manage = RequirePermission::for('integrations.manage');
$uuid = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

return [
    Group::create('/integrations')->middleware(RequirePermission::for('integrations.view'))->routes(
        Route::get('')->action([I::class, 'index'])->name('admin.integrations'),
        Route::get('/new')->middleware($manage)->action([I::class, 'create'])->name('admin.integrations.create'),
        Route::post('')->middleware($manage)->action([I::class, 'store'])->name('admin.integrations.store'),
        Route::get('/logs')->action([I::class, 'logs'])->name('admin.integrations.logs'),
        Route::get('/logs/{logId:\d+}')->action([I::class, 'log'])->name('admin.integrations.log'),
        Route::get('/webhooks')->action([W::class, 'index'])->name('admin.integrations.webhooks'),
        Route::get('/webhooks/new')->middleware($manage)->action([W::class, 'create'])->name('admin.integrations.webhooks.create'),
        Route::post('/webhooks')->middleware($manage)->action([W::class, 'store'])->name('admin.integrations.webhooks.store'),
        Route::get("/webhooks/$uuid")->action([W::class, 'show'])->name('admin.integrations.webhooks.show'),
        Route::get("/webhooks/$uuid/edit")->middleware($manage)->action([W::class, 'edit'])->name('admin.integrations.webhooks.edit'),
        Route::post("/webhooks/$uuid")->middleware($manage)->action([W::class, 'update'])->name('admin.integrations.webhooks.update'),
        Route::post("/webhooks/$uuid/toggle")->middleware($manage)->action([W::class, 'toggle'])->name('admin.integrations.webhooks.toggle'),
        Route::post("/webhooks/$uuid/rotate")->middleware($manage, RequireRecentAuth::class)->action([W::class, 'rotate'])->name('admin.integrations.webhooks.rotate'),
        Route::post("/webhooks/$uuid/delete")->middleware($manage, RequireRecentAuth::class)->action([W::class, 'delete'])->name('admin.integrations.webhooks.delete'),
        Route::get("/webhooks/$uuid/events/{eventId:\d+}")->action([W::class, 'event'])->name('admin.integrations.webhooks.event'),
        Route::get("/$uuid")->action([I::class, 'show'])->name('admin.integrations.show'),
        Route::get("/$uuid/edit")->middleware($manage)->action([I::class, 'edit'])->name('admin.integrations.edit'),
        Route::post("/$uuid")->middleware($manage)->action([I::class, 'update'])->name('admin.integrations.update'),
        Route::post("/$uuid/toggle")->middleware($manage)->action([I::class, 'toggle'])->name('admin.integrations.toggle'),
        Route::post("/$uuid/security")->middleware($manage, RequireRecentAuth::class)->action([I::class, 'security'])->name('admin.integrations.security'),
        Route::post("/$uuid/test")->middleware($manage)->action([I::class, 'test'])->name('admin.integrations.test'),
        Route::post("/$uuid/circuit/reset")->middleware($manage)->action([I::class, 'resetCircuit'])->name('admin.integrations.circuit_reset'),
        Route::post("/$uuid/delete")->middleware($manage, RequireRecentAuth::class)->action([I::class, 'delete'])->name('admin.integrations.delete'),
    ),
];
