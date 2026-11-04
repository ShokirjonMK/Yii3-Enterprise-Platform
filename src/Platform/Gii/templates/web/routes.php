<?php

declare(strict_types=1);

/**
 * Template: web routes (config/routes/web/<module>.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$status = $m->hasStatus()
    ? "\n            Route::post('/' . \$id . '/status')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'status'])->name('web.{{code}}.status'),"
    : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use App\Api\V1\Middleware\RequireFeature;
use App\Web\Action\{{Module}}\{{Module}}Controller;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

/** End-user {{label}} pages (inside the authenticated web group). */
return [
    Group::create('/{{slug}}')
        ->middleware(RequireFeature::for('{{flag}}'), RequirePermission::for('{{code}}.view'))
        ->routes(
            Route::get('')->action([{{Module}}Controller::class, 'index'])->name('web.{{code}}'),
            Route::get('/new')->middleware(RequirePermission::for('{{code}}.create'))->action([{{Module}}Controller::class, 'createForm'])->name('web.{{code}}.create'),
            Route::post('/new')->middleware(RequirePermission::for('{{code}}.create'))->action([{{Module}}Controller::class, 'store'])->name('web.{{code}}.store'),
            Route::get('/' . $id)->action([{{Module}}Controller::class, 'view'])->name('web.{{code}}.view'),
            Route::get('/' . $id . '/edit')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'editForm'])->name('web.{{code}}.edit'),
            Route::post('/' . $id . '/edit')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'update'])->name('web.{{code}}.update'),__STATUS__
        ),
];

PHP, ['__STATUS__' => $status]);
