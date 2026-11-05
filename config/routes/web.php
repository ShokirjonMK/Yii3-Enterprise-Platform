<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use App\Web\Action\Account\ProfileController;
use App\Web\Action\Home\HomeController;
use App\Web\Action\Notifications\InboxController;
use App\Web\Middleware\AuthContextMiddleware;
use App\Web\Middleware\LocaleMiddleware;
use App\Web\Middleware\RequireLogin;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Session\SessionMiddleware;

/**
 * End-user web application. Business modules register their own web routes in
 * config/routes/web/*.php (loaded inside the authenticated group below).
 */
$moduleRoutes = \App\Platform\FeatureFlags\FeatureRegistry::loadRoutes('web', [
    ...(glob(dirname(__DIR__, 2) . '/src/Modules/*/Web/routes.php') ?: []),
    ...(glob(__DIR__ . '/web/*.php') ?: []),
]);

return [
    Group::create()
        ->middleware(SessionMiddleware::class, CsrfTokenMiddleware::class, LocaleMiddleware::class, AuthContextMiddleware::class, DomainErrorMiddleware::class, \App\Platform\Audit\ActivityLogMiddleware::class)
        ->routes(
            Route::get('/')->action([HomeController::class, 'index'])->name('web.home'),

            Group::create()
                ->middleware(RequireLogin::class)
                ->routes(
                    Route::get('/home')->action([HomeController::class, 'redirect'])->name('home.redirect'),
                    Route::get('/account')->action([ProfileController::class, 'show'])->name('web.profile'),
                    Route::post('/account')->action([ProfileController::class, 'update'])->name('web.profile.update'),
                    Route::get('/account/security')->action([ProfileController::class, 'security'])->name('web.security'),
                    Route::post('/account/sessions/{id:[0-9a-f]{64}}/revoke')->action([ProfileController::class, 'revokeSession'])->name('web.security.session.revoke'),
                    Route::post('/account/sessions/revoke-others')->action([ProfileController::class, 'revokeOtherSessions'])->name('web.security.sessions.revoke_others'),
                    Route::post('/account/tokens')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'createToken'])->name('web.security.token.create'),
                    Route::post('/account/tokens/{id}/revoke')->action([ProfileController::class, 'revokeToken'])->name('web.security.token.revoke'),
                    Route::post('/account/mfa/recovery-codes')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'regenerateRecoveryCodes'])->name('web.security.mfa.codes'),
                    Route::post('/account/mfa/disable')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'disableMfa'])->name('web.security.mfa.disable'),
                    Route::post('/account/mfa/methods/{id:totp|[0-9a-f-]{36}}/remove')->middleware(RequireRecentAuth::class)->action([\App\Web\Action\Account\MfaMethodsController::class, 'remove'])->name('web.security.mfa.remove'),
                    Route::post('/account/mfa/preferred')->action([\App\Web\Action\Account\MfaMethodsController::class, 'preferred'])->name('web.security.mfa.preferred'),
                    Route::post('/account/mfa/devices/{id:[0-9a-f-]{36}}/revoke')->action([\App\Web\Action\Account\MfaMethodsController::class, 'revokeDevice'])->name('web.security.mfa.device.revoke'),
                    Route::post('/account/mfa/devices/revoke-all')->action([\App\Web\Action\Account\MfaMethodsController::class, 'revokeAllDevices'])->name('web.security.mfa.devices.revoke_all'),
                    Route::get('/notifications')->action([InboxController::class, 'index'])->name('web.notifications'),
                    Route::get('/notifications/badge')->action([InboxController::class, 'badge'])->name('web.notifications.badge'),
                    Route::post('/notifications/{id}/read')->action([InboxController::class, 'markRead'])->name('web.notifications.read'),
                    Route::post('/notifications/read-all')->action([InboxController::class, 'markAllRead'])->name('web.notifications.read_all'),
                    ...$moduleRoutes,
                ),
        ),
];
