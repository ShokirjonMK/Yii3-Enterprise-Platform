<?php

declare(strict_types=1);

use App\Api\V1\Middleware\BearerAuthentication;
use App\Api\V1\Middleware\RequireApiPermission;
use App\Api\V1\Middleware\RequireFeature;
use App\Modules\MultiDb\Api\MultiDbController;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$ds = '{dataset:[a-z]{2,32}}';
$id = '{id:[0-9A-Za-z-]{1,64}}';

/**
 * Multi-DB demo resource (flag module.multidb; scopes multidb.read / multidb.write — or "*" — plus the RBAC permission).
 * "/stats" is registered before "/{id}".
 */
return [
    Group::create('/multidb')
        ->middleware(BearerAuthentication::class, RequireFeature::for('module.multidb'))
        ->routes(
            Route::get('')->middleware(RequireApiPermission::for('multidb.view', 'multidb.read'))->action([MultiDbController::class, 'datasets'])->name('api.v1.multidb'),
            Route::get('/' . $ds)->middleware(RequireApiPermission::for('multidb.view', 'multidb.read'))->action([MultiDbController::class, 'index'])->name('api.v1.multidb.list'),
            Route::post('/' . $ds)->middleware(RequireApiPermission::for('multidb.create', 'multidb.write'))->action([MultiDbController::class, 'create'])->name('api.v1.multidb.create'),
            Route::get('/' . $ds . '/stats')->middleware(RequireApiPermission::for('multidb.view', 'multidb.read'))->action([MultiDbController::class, 'stats'])->name('api.v1.multidb.stats'),
            Route::get('/' . $ds . '/' . $id)->middleware(RequireApiPermission::for('multidb.view', 'multidb.read'))->action([MultiDbController::class, 'view'])->name('api.v1.multidb.view'),
            Route::patch('/' . $ds . '/' . $id)->middleware(RequireApiPermission::for('multidb.update', 'multidb.write'))->action([MultiDbController::class, 'update'])->name('api.v1.multidb.update'),
            Route::delete('/' . $ds . '/' . $id)->middleware(RequireApiPermission::for('multidb.delete', 'multidb.write'))->action([MultiDbController::class, 'delete'])->name('api.v1.multidb.delete'),
        ),
];
