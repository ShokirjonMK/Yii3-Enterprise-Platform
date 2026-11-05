<?php

declare(strict_types=1);

use App\Web\Notifications\InboxController;
use App\Web\Notifications\PushSubscriptionController;
use Yiisoft\Router\Route;

/**
 * Notifications area: in-app inbox and Web Push subscriptions. Loaded inside the authenticated group of
 * config/routes/web.php.
 */
return [
    Route::get('/notifications')->action([InboxController::class, 'index'])->name('web.notifications'),
    Route::get('/notifications/badge')->action([InboxController::class, 'badge'])->name('web.notifications.badge'),
    Route::post('/notifications/{id}/read')->action([InboxController::class, 'markRead'])->name('web.notifications.read'),
    Route::post('/notifications/read-all')->action([InboxController::class, 'markAllRead'])->name('web.notifications.read_all'),
    Route::get('/notifications/push/key')->action([PushSubscriptionController::class, 'key'])->name('web.push.key'),
    Route::post('/notifications/push/subscribe')->action([PushSubscriptionController::class, 'subscribe'])->name('web.push.subscribe'),
    Route::post('/notifications/push/unsubscribe')->action([PushSubscriptionController::class, 'unsubscribe'])->name('web.push.unsubscribe'),
];
