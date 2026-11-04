<?php

declare(strict_types=1);

use App\Web\Action\Notifications\PushSubscriptionController;
use Yiisoft\Router\Route;

/** Web Push subscriptions of the signed-in user (inside the authenticated web group: session, CSRF, login). */
return [
    Route::get('/notifications/push/key')->action([PushSubscriptionController::class, 'key'])->name('web.push.key'),
    Route::post('/notifications/push/subscribe')->action([PushSubscriptionController::class, 'subscribe'])->name('web.push.subscribe'),
    Route::post('/notifications/push/unsubscribe')->action([PushSubscriptionController::class, 'unsubscribe'])->name('web.push.unsubscribe'),
];
