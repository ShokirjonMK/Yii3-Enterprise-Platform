<?php

declare(strict_types=1);

use App\Admin\Deployments\DeploymentsController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 16 · CI/CD & Deployments (four-eyes: approver ≠ requester, enforced in DeploymentService + DB constraint) */
$uuid = '/{id:[0-9a-fA-F-]{36}}';

return [
    Group::create('/deployments')->middleware(RequirePermission::for('deploy.view'))->routes(
        Route::get('')->action([DeploymentsController::class, 'index'])->name('admin.deployments'),
        Route::post('')->middleware(RequirePermission::for('deploy.request'))
            ->action([DeploymentsController::class, 'request'])->name('admin.deployments.request'),
        Route::get($uuid)->action([DeploymentsController::class, 'view'])->name('admin.deployments.view'),
        Route::post($uuid . '/approve')->middleware(RequirePermission::for('deploy.approve'))->middleware(RequireRecentAuth::class)
            ->action([DeploymentsController::class, 'approve'])->name('admin.deployments.approve'),
        Route::post($uuid . '/reject')->middleware(RequirePermission::for('deploy.approve'))->middleware(RequireRecentAuth::class)
            ->action([DeploymentsController::class, 'reject'])->name('admin.deployments.reject'),
        Route::post($uuid . '/trigger')->middleware(RequirePermission::for('deploy.approve'))->middleware(RequireRecentAuth::class)
            ->action([DeploymentsController::class, 'trigger'])->name('admin.deployments.trigger'),
        Route::post($uuid . '/refresh')->middleware(RequirePermission::for('deploy.request', 'deploy.approve'))
            ->action([DeploymentsController::class, 'refresh'])->name('admin.deployments.refresh'),
        Route::post($uuid . '/rollback')->middleware(RequirePermission::for('deploy.request'))->middleware(RequireRecentAuth::class)
            ->action([DeploymentsController::class, 'rollback'])->name('admin.deployments.rollback'),
    ),
];
