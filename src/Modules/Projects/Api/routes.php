<?php

declare(strict_types=1);

use App\Modules\Projects\Api\ProjectsController;
use App\Api\V1\Middleware\BearerAuthentication;
use App\Api\V1\Middleware\RequireApiPermission;
use App\Api\V1\Middleware\RequireFeature;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-fA-F-]{36}}';

/** Projects module (feature flag module.projects; evaluated per tenant/user after authentication). */
return [
    Group::create('/projects')
        ->middleware(BearerAuthentication::class, RequireFeature::for('module.projects'))
        ->routes(
            Route::get('')->middleware(RequireApiPermission::for('projects.view', 'projects.read'))->action([ProjectsController::class, 'index'])->name('api.v1.projects'),
            Route::post('')->middleware(RequireApiPermission::for('projects.create', 'projects.write'))->action([ProjectsController::class, 'create'])->name('api.v1.projects.create'),
            Route::post('/reports')->middleware(RequireApiPermission::for('projects.view', 'projects.read'))->action([ProjectsController::class, 'report'])->name('api.v1.projects.reports.create'),
            Route::get('/reports/' . $id)->middleware(RequireApiPermission::for('projects.view', 'projects.read'))->action([ProjectsController::class, 'reportStatus'])->name('api.v1.projects.reports.view'),
            Route::get('/' . $id)->middleware(RequireApiPermission::for('projects.view', 'projects.read'))->action([ProjectsController::class, 'view'])->name('api.v1.projects.view'),
            Route::patch('/' . $id)->middleware(RequireApiPermission::for('projects.update', 'projects.write'))->action([ProjectsController::class, 'update'])->name('api.v1.projects.update'),
            Route::delete('/' . $id)->middleware(RequireApiPermission::for('projects.delete', 'projects.write'))->action([ProjectsController::class, 'archive'])->name('api.v1.projects.archive'),
            Route::post('/' . $id . '/status')->middleware(RequireApiPermission::for('projects.update', 'projects.write'))->action([ProjectsController::class, 'status'])->name('api.v1.projects.status'),
        ),
];
