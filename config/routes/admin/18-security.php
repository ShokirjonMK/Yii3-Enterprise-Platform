<?php

declare(strict_types=1);

use App\Admin\Action\Security\SecurityController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 18 · Security Center */
$uuid = '{id:[0-9a-fA-F-]{36}}';

return [
    Group::create('/security')->middleware(RequirePermission::for('security.view'))->routes(
        Route::get('')->action([SecurityController::class, 'index'])->name('admin.security'),
        Route::get('/sessions')->action([SecurityController::class, 'sessions'])->name('admin.security.sessions'),
        Route::get('/asvs')->action([SecurityController::class, 'asvs'])->name('admin.security.asvs'),
        Route::post('/sessions/{id:[0-9a-f]{64}}/revoke')->middleware(RequirePermission::for('sessions.revoke'))
            ->action([SecurityController::class, 'revokeSession'])->name('admin.security.sessions.revoke'),
        Route::post('/users/' . $uuid . '/sessions/revoke')->middleware(RequirePermission::for('sessions.revoke'))->middleware(RequireRecentAuth::class)
            ->action([SecurityController::class, 'revokeUserSessions'])->name('admin.security.sessions.revoke_user'),
        Route::post('/users/' . $uuid . '/tokens/revoke')->middleware(RequirePermission::for('security.manage'))->middleware(RequireRecentAuth::class)
            ->action([SecurityController::class, 'revokeTokens'])->name('admin.security.tokens.revoke'),
        Route::post('/users/' . $uuid . '/unlock')->middleware(RequirePermission::for('security.manage'))
            ->action([SecurityController::class, 'unlock'])->name('admin.security.unlock'),
    ),
];
