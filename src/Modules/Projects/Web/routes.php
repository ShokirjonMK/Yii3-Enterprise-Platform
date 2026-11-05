<?php

declare(strict_types=1);

use App\Api\V1\Middleware\RequireFeature;
use App\Modules\Projects\Web\ProjectsController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$projectId = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

/** End-user Projects pages (inside the authenticated web group). */
return [
    Group::create('/projects')
        ->middleware(RequireFeature::for('module.projects'), RequirePermission::for('projects.view'))
        ->routes(
            Route::get('')->action([ProjectsController::class, 'index'])->name('web.projects'),
            Route::get('/new')->middleware(RequirePermission::for('projects.create'))->action([ProjectsController::class, 'createForm'])->name('web.projects.create'),
            Route::post('/new')->middleware(RequirePermission::for('projects.create'))->action([ProjectsController::class, 'store'])->name('web.projects.store'),
            Route::get('/' . $projectId)->action([ProjectsController::class, 'view'])->name('web.projects.view'),
            Route::get('/' . $projectId . '/edit')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'editForm'])->name('web.projects.edit'),
            Route::post('/' . $projectId . '/edit')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'update'])->name('web.projects.update'),
            Route::post('/' . $projectId . '/status')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'status'])->name('web.projects.status'),
        ),
];
