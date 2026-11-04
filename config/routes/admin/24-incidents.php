<?php

declare(strict_types=1);

use App\Admin\Action\Incidents\IncidentsController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 24 · Incident Management */
$uuid = '/{id:[0-9a-fA-F-]{36}}';
$manage = RequirePermission::for('incidents.manage');

return [
    Group::create('/incidents')->middleware(RequirePermission::for('incidents.view'))->routes(
        Route::get('')->action([IncidentsController::class, 'index'])->name('admin.incidents'),
        Route::post('')->middleware($manage)->action([IncidentsController::class, 'open'])->name('admin.incidents.open'),
        Route::get($uuid)->action([IncidentsController::class, 'view'])->name('admin.incidents.view'),
        Route::post($uuid . '/acknowledge')->middleware($manage)->action([IncidentsController::class, 'acknowledge'])->name('admin.incidents.ack'),
        Route::post($uuid . '/status')->middleware($manage)->action([IncidentsController::class, 'transition'])->name('admin.incidents.transition'),
        Route::post($uuid . '/notes')->middleware($manage)->action([IncidentsController::class, 'note'])->name('admin.incidents.note'),
        Route::post($uuid . '/commander')->middleware($manage)->action([IncidentsController::class, 'commander'])->name('admin.incidents.commander'),
        Route::post($uuid . '/postmortem')->middleware($manage)->action([IncidentsController::class, 'postmortem'])->name('admin.incidents.postmortem'),
    ),
];
