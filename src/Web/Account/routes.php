<?php

declare(strict_types=1);

use App\Web\Account\AppearanceController;
use App\Web\Account\MfaMethodsController;
use App\Web\Account\ProfileController;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Route;

/**
 * Account area (/account): profile, security (sessions, API tokens, 2FA methods, remembered devices), appearance.
 * Loaded inside the authenticated group of config/routes/web.php.
 */
return [
    Route::get('/account')->action([ProfileController::class, 'show'])->name('web.profile'),
    Route::post('/account')->action([ProfileController::class, 'update'])->name('web.profile.update'),
    Route::get('/account/security')->action([ProfileController::class, 'security'])->name('web.security'),
    Route::post('/account/sessions/{id:[0-9a-f]{64}}/revoke')->action([ProfileController::class, 'revokeSession'])->name('web.security.session.revoke'),
    Route::post('/account/sessions/revoke-others')->action([ProfileController::class, 'revokeOtherSessions'])->name('web.security.sessions.revoke_others'),
    Route::post('/account/tokens')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'createToken'])->name('web.security.token.create'),
    Route::post('/account/tokens/{id}/revoke')->action([ProfileController::class, 'revokeToken'])->name('web.security.token.revoke'),
    Route::post('/account/mfa/recovery-codes')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'regenerateRecoveryCodes'])->name('web.security.mfa.codes'),
    Route::post('/account/mfa/disable')->middleware(RequireRecentAuth::class)->action([ProfileController::class, 'disableMfa'])->name('web.security.mfa.disable'),
    Route::post('/account/mfa/methods/{id:totp|[0-9a-f-]{36}}/remove')->middleware(RequireRecentAuth::class)->action([MfaMethodsController::class, 'remove'])->name('web.security.mfa.remove'),
    Route::post('/account/mfa/preferred')->action([MfaMethodsController::class, 'preferred'])->name('web.security.mfa.preferred'),
    Route::post('/account/mfa/devices/{id:[0-9a-f-]{36}}/revoke')->action([MfaMethodsController::class, 'revokeDevice'])->name('web.security.mfa.device.revoke'),
    Route::post('/account/mfa/devices/revoke-all')->action([MfaMethodsController::class, 'revokeAllDevices'])->name('web.security.mfa.devices.revoke_all'),
    Route::get('/account/appearance')->action([AppearanceController::class, 'show'])->name('web.appearance'),
    Route::post('/account/appearance')->action([AppearanceController::class, 'save'])->name('web.appearance.save'),
];
