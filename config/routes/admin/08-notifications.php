<?php

declare(strict_types=1);

use App\Admin\Action\Notifications\NotificationsController as N;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$manage = RequirePermission::for('notifications.manage');
$uuid = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';
$channel = '{code:(?:inapp|email|telegram|sms|webpush)}';

return [
    Group::create('/notifications')->middleware(RequirePermission::for('notifications.view'))->routes(
        Route::get('')->action([N::class, 'index'])->name('admin.notifications'),
        Route::post("/channels/$channel/health")->action([N::class, 'health'])->name('admin.notifications.health'),
        Route::post("/channels/$channel/toggle")->middleware($manage)->action([N::class, 'toggle'])->name('admin.notifications.toggle'),
        Route::post("/channels/$channel/test")->middleware($manage)->action([N::class, 'testSend'])->name('admin.notifications.test'),

        Route::get('/templates')->action([N::class, 'templates'])->name('admin.notifications.templates'),
        Route::get('/templates/new')->middleware($manage)->action([N::class, 'createTemplate'])->name('admin.notifications.templates.create'),
        Route::post('/templates')->middleware($manage)->action([N::class, 'storeTemplate'])->name('admin.notifications.templates.store'),
        Route::post('/templates/preview')->action([N::class, 'preview'])->name('admin.notifications.templates.preview'),
        Route::get("/templates/$uuid")->action([N::class, 'editTemplate'])->name('admin.notifications.templates.edit'),
        Route::post("/templates/$uuid")->middleware($manage)->action([N::class, 'updateTemplate'])->name('admin.notifications.templates.update'),
        Route::post("/templates/$uuid/delete")->middleware($manage)->action([N::class, 'deleteTemplate'])->name('admin.notifications.templates.delete'),

        Route::get('/deliveries')->action([N::class, 'deliveries'])->name('admin.notifications.deliveries'),
        Route::post('/deliveries/retry-failed')->middleware($manage)->action([N::class, 'retryFailed'])->name('admin.notifications.retry_failed'),
        Route::get("/deliveries/$uuid")->action([N::class, 'delivery'])->name('admin.notifications.delivery'),
        Route::post("/deliveries/$uuid/retry")->middleware($manage)->action([N::class, 'retry'])->name('admin.notifications.retry'),

        Route::get('/broadcast')->middleware(RequirePermission::for('notifications.send'))->action([N::class, 'broadcastForm'])->name('admin.notifications.broadcast'),
        Route::post('/broadcast')->middleware(RequirePermission::for('notifications.send'))->action([N::class, 'broadcast'])->name('admin.notifications.broadcast.send'),
    ),
];
