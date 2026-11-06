<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use App\Web\Auth\AccountSecurityController;
use App\Web\Auth\LoginController;
use App\Web\Auth\MfaSetupController;
use App\Web\Auth\TelegramWebhookController;
use App\Web\Auth\SsoController;
use App\Web\Middleware\AuthContextMiddleware;
use App\Web\Middleware\LocaleMiddleware;
use App\Web\Middleware\RequireLogin;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Session\SessionMiddleware;

/**
 * Authentication routes: login, MFA, SSO, forced password change, MFA enrolment, step-up re-auth.
 */
return [
    Group::create()
        ->middleware(SessionMiddleware::class, CsrfTokenMiddleware::class, LocaleMiddleware::class, AuthContextMiddleware::class, DomainErrorMiddleware::class)
        ->routes(
            Route::get('/login')->action([LoginController::class, 'show'])->name('auth.login'),
            Route::post('/login')->action([LoginController::class, 'submit'])->name('auth.login.submit'),
            Route::get('/login/mfa')->action([LoginController::class, 'mfaShow'])->name('auth.mfa'),
            Route::post('/login/mfa')->action([LoginController::class, 'mfaSubmit'])->name('auth.mfa.submit'),
            Route::post('/login/mfa/send')->action([LoginController::class, 'mfaSend'])->name('auth.mfa.send'),
            Route::post('/login/mfa/passkey')->action([LoginController::class, 'mfaPasskeyOptions'])->name('auth.mfa.passkey'),
            Route::post('/login/mfa/eimzo')->action([LoginController::class, 'mfaEimzoChallenge'])->name('auth.mfa.eimzo'),
            Route::post('/auth/locale')->action([LoginController::class, 'locale'])->name('auth.locale'),
            Route::get('/auth/sso/{provider:[a-z0-9_-]+}')->action([SsoController::class, 'start'])->name('auth.sso.start'),
            Route::get('/auth/sso/{provider:[a-z0-9_-]+}/callback')->action([SsoController::class, 'callback'])->name('auth.sso.callback'),
            Group::create()
                ->middleware(RequireLogin::class)
                ->routes(
                    Route::post('/logout')->action([LoginController::class, 'logout'])->name('auth.logout'),
                    Route::get('/auth/password')->action([AccountSecurityController::class, 'passwordForm'])->name('auth.password.change'),
                    Route::post('/auth/password')->action([AccountSecurityController::class, 'passwordSubmit'])->name('auth.password.submit'),
                    // two-factor enrolment (also reachable while the mandatory "mfa_setup" gate is pending)
                    Route::get('/auth/mfa/setup')->action([MfaSetupController::class, 'show'])->name('auth.mfa.setup'),
                    Route::post('/auth/mfa/setup')->action([MfaSetupController::class, 'confirmTotp'])->name('auth.mfa.setup.confirm'),
                    Route::post('/auth/mfa/setup/telegram')->action([MfaSetupController::class, 'telegram'])->name('auth.mfa.setup.telegram'),
                    Route::get('/auth/mfa/setup/telegram/status')->action([MfaSetupController::class, 'telegramStatus'])->name('auth.mfa.setup.telegram.status'),
                    Route::post('/auth/mfa/setup/code')->action([MfaSetupController::class, 'otpSend'])->name('auth.mfa.setup.otp.send'),
                    Route::post('/auth/mfa/setup/code/verify')->action([MfaSetupController::class, 'otpVerify'])->name('auth.mfa.setup.otp.verify'),
                    Route::post('/auth/mfa/setup/passkey/options')->action([MfaSetupController::class, 'passkeyOptions'])->name('auth.mfa.setup.passkey.options'),
                    Route::post('/auth/mfa/setup/passkey')->action([MfaSetupController::class, 'passkey'])->name('auth.mfa.setup.passkey'),
                    Route::post('/auth/mfa/setup/eimzo/challenge')->action([MfaSetupController::class, 'eimzoChallenge'])->name('auth.mfa.setup.eimzo.challenge'),
                    Route::post('/auth/mfa/setup/eimzo')->action([MfaSetupController::class, 'eimzo'])->name('auth.mfa.setup.eimzo'),
                    Route::get('/auth/mfa/recovery')->action([MfaSetupController::class, 'recovery'])->name('auth.mfa.recovery'),
                    Route::get('/auth/confirm')->action([AccountSecurityController::class, 'confirmForm'])->name('auth.confirm'),
                    Route::post('/auth/confirm')->action([AccountSecurityController::class, 'confirmSubmit'])->name('auth.confirm.submit'),
                    Route::post('/auth/confirm/send')->action([AccountSecurityController::class, 'confirmSend'])->name('auth.confirm.send'),
                    Route::post('/auth/confirm/passkey')->action([AccountSecurityController::class, 'confirmPasskey'])->name('auth.confirm.passkey'),
                    Route::post('/auth/confirm/eimzo')->action([AccountSecurityController::class, 'confirmEimzo'])->name('auth.confirm.eimzo'),
                ),
        ),

    // Telegram Bot API webhook (mode "webhook"): no session/CSRF; authenticated by X-Telegram-Bot-Api-Secret-Token
    Route::post('/integrations/telegram/webhook')->action([TelegramWebhookController::class, 'handle'])->name('telegram.webhook'),
];
