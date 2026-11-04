<?php

declare(strict_types=1);

/**
 * Template: admin routes (config/routes/admin/<NN>-<module>.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$extra = [];
if ($m->has('report')) {
    $extra[] = "            Route::post('/reports')->action([{{Module}}Controller::class, 'requestReport'])->name('admin.{{code}}.report.request'),";
    $extra[] = "            Route::get('/reports/' . \$id)->action([{{Module}}Controller::class, 'report'])->name('admin.{{code}}.report'),";
    $extra[] = "            Route::get('/reports/' . \$id . '/download')->action([{{Module}}Controller::class, 'download'])->name('admin.{{code}}.report.download'),";
}
$status = $m->hasStatus()
    ? "\n            Route::post('/' . \$id . '/status')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'status'])->name('admin.{{code}}.status'),"
    : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use App\Admin\Action\{{Module}}\{{Module}}Controller;
use App\Api\V1\Middleware\RequireFeature;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

/** {{label}} module (flag {{flag}}); prefix /admin is added by config/routes/admin.php. */
return [
    Group::create('/{{slug}}')
        ->middleware(RequireFeature::for('{{flag}}'), RequirePermission::for('{{code}}.view'))
        ->routes(
            Route::get('')->action([{{Module}}Controller::class, 'index'])->name('admin.{{code}}'),
            Route::get('/create')->middleware(RequirePermission::for('{{code}}.create'))->action([{{Module}}Controller::class, 'createForm'])->name('admin.{{code}}.create'),
            Route::post('/create')->middleware(RequirePermission::for('{{code}}.create'))->action([{{Module}}Controller::class, 'store'])->name('admin.{{code}}.store'),
__EXTRA__            Route::get('/' . $id)->action([{{Module}}Controller::class, 'view'])->name('admin.{{code}}.view'),
            Route::get('/' . $id . '/edit')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'editForm'])->name('admin.{{code}}.edit'),
            Route::post('/' . $id . '/edit')->middleware(RequirePermission::for('{{code}}.update'))->action([{{Module}}Controller::class, 'update'])->name('admin.{{code}}.update'),__STATUS__
            Route::post('/' . $id . '/delete')->middleware(RequirePermission::for('{{code}}.delete'))->action([{{Module}}Controller::class, 'delete'])->name('admin.{{code}}.delete'),
        ),
];

PHP, ['__EXTRA__' => $extra === [] ? '' : implode("\n", $extra) . "\n", '__STATUS__' => $status]);
