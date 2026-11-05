<?php

declare(strict_types=1);

use App\Admin\Infrastructure\InfrastructureController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 15 · Infrastructure */
return [
    Group::create('/infrastructure')->middleware(RequirePermission::for('infra.view'))->routes(
        Route::get('')->action([InfrastructureController::class, 'index'])->name('admin.infrastructure'),
        Route::get('/kubernetes')->action([InfrastructureController::class, 'kubernetes'])->name('admin.infrastructure.k8s'),
        Route::post('/kubernetes/scale')->middleware(RequirePermission::for('infra.manage'))->middleware(RequireRecentAuth::class)
            ->action([InfrastructureController::class, 'scale'])->name('admin.infrastructure.scale'),
    ),
];
