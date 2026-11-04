<?php

declare(strict_types=1);

use App\Admin\Action\DevTools\DevToolsController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Route;

/**
 * 22 · Developer Tools. The controller returns 404 in production or when flag devtools.gii is off.
 * The Enterprise Gii team adds admin.devtools.gii in its own route file.
 */
return [
    Route::get('/devtools')->middleware(RequirePermission::for('devtools.use'))->action([DevToolsController::class, 'index'])->name('admin.devtools'),
];
