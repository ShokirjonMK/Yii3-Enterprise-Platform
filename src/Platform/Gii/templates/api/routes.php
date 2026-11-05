<?php

declare(strict_types=1);

/**
 * Template: API routes (src/Modules/<Module>/Api/routes.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$status = $m->hasStatus()
    ? "\n            Route::post('/' . \$id . '/status')->middleware(RequireApiPermission::for('{{code}}.update', '{{code}}.write'))->action([{{Module}}Controller::class, 'status'])->name('api.v1.{{code}}.status'),"
    : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use App\Modules\{{Module}}\Api\{{Module}}Controller;
use App\Api\V1\Middleware\BearerAuthentication;
use App\Api\V1\Middleware\RequireApiPermission;
use App\Api\V1\Middleware\RequireFeature;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-fA-F-]{36}}';

/**
 * {{label}} resource (flag {{flag}}; scopes {{code}}.read / {{code}}.write — or "*" — plus the RBAC permission).
 */
return [
    Group::create('/{{slug}}')
        ->middleware(BearerAuthentication::class, RequireFeature::for('{{flag}}'))
        ->routes(
            Route::get('')->middleware(RequireApiPermission::for('{{code}}.view', '{{code}}.read'))->action([{{Module}}Controller::class, 'index'])->name('api.v1.{{code}}'),
            Route::post('')->middleware(RequireApiPermission::for('{{code}}.create', '{{code}}.write'))->action([{{Module}}Controller::class, 'create'])->name('api.v1.{{code}}.create'),
            Route::get('/' . $id)->middleware(RequireApiPermission::for('{{code}}.view', '{{code}}.read'))->action([{{Module}}Controller::class, 'view'])->name('api.v1.{{code}}.view'),
            Route::patch('/' . $id)->middleware(RequireApiPermission::for('{{code}}.update', '{{code}}.write'))->action([{{Module}}Controller::class, 'update'])->name('api.v1.{{code}}.update'),
            Route::delete('/' . $id)->middleware(RequireApiPermission::for('{{code}}.delete', '{{code}}.write'))->action([{{Module}}Controller::class, 'delete'])->name('api.v1.{{code}}.delete'),__STATUS__
        ),
];

PHP, ['__STATUS__' => $status]);
