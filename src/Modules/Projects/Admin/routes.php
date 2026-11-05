<?php

declare(strict_types=1);

use App\Modules\Projects\Admin\ProjectsController;
use App\Api\V1\Middleware\RequireFeature;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

/** Projects sample business module (flag module.projects). */
return [
    Group::create('/projects')
        ->middleware(RequireFeature::for('module.projects'), RequirePermission::for('projects.view'))
        ->routes(
            Route::get('')->action([ProjectsController::class, 'index'])->name('admin.projects'),
            Route::get('/create')->middleware(RequirePermission::for('projects.create'))->action([ProjectsController::class, 'createForm'])->name('admin.projects.create'),
            Route::post('/create')->middleware(RequirePermission::for('projects.create'))->action([ProjectsController::class, 'store'])->name('admin.projects.store'),
            Route::post('/reports')->action([ProjectsController::class, 'requestReport'])->name('admin.projects.report.request'),
            Route::get('/reports/' . $id)->action([ProjectsController::class, 'report'])->name('admin.projects.report'),
            Route::get('/reports/' . $id . '/download')->action([ProjectsController::class, 'download'])->name('admin.projects.report.download'),
            Route::get('/' . $id)->action([ProjectsController::class, 'view'])->name('admin.projects.view'),
            Route::get('/' . $id . '/edit')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'editForm'])->name('admin.projects.edit'),
            Route::post('/' . $id . '/edit')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'update'])->name('admin.projects.update'),
            Route::post('/' . $id . '/status')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'status'])->name('admin.projects.status'),
            Route::post('/' . $id . '/archive')->middleware(RequirePermission::for('projects.delete'))->action([ProjectsController::class, 'archive'])->name('admin.projects.archive'),
            Route::post('/' . $id . '/members')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'addMember'])->name('admin.projects.members.add'),
            Route::post('/' . $id . '/members/{userId:[0-9a-f-]{36}}/remove')->middleware(RequirePermission::for('projects.update'))->action([ProjectsController::class, 'removeMember'])->name('admin.projects.members.remove'),
        ),
];
