<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use App\Web\Action\Auth\AccountSecurityController;
use App\Web\Action\Auth\LoginController;
use App\Web\Action\Auth\SsoController;
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
            Route::post('/auth/locale')->action([LoginController::class, 'locale'])->name('auth.locale'),
            Route::get('/auth/sso/{provider:[a-z0-9_-]+}')->action([SsoController::class, 'start'])->name('auth.sso.start'),
            Route::get('/auth/sso/{provider:[a-z0-9_-]+}/callback')->action([SsoController::class, 'callback'])->name('auth.sso.callback'),

            Group::create()
                ->middleware(RequireLogin::class)
                ->routes(
                    Route::post('/logout')->action([LoginController::class, 'logout'])->name('auth.logout'),
                    Route::get('/auth/password')->action([AccountSecurityController::class, 'passwordForm'])->name('auth.password.change'),
                    Route::post('/auth/password')->action([AccountSecurityController::class, 'passwordSubmit'])->name('auth.password.submit'),
                    Route::get('/auth/mfa/setup')->action([AccountSecurityController::class, 'mfaSetupForm'])->name('auth.mfa.setup'),
                    Route::post('/auth/mfa/setup')->action([AccountSecurityController::class, 'mfaSetupSubmit'])->name('auth.mfa.setup.confirm'),
                    Route::get('/auth/confirm')->action([AccountSecurityController::class, 'confirmForm'])->name('auth.confirm'),
                    Route::post('/auth/confirm')->action([AccountSecurityController::class, 'confirmSubmit'])->name('auth.confirm.submit'),
                ),
        ),
];
