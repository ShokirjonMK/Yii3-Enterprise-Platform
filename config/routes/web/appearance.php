<?php

declare(strict_types=1);

use App\Web\Action\Account\AppearanceController;
use Yiisoft\Router\Route;

/** Account → Appearance: the signed-in user's own design theme (when the administrator allows it). Author: ShokirjonMK */
return [
    Route::get('/account/appearance')->action([AppearanceController::class, 'show'])->name('web.appearance'),
    Route::post('/account/appearance')->action([AppearanceController::class, 'save'])->name('web.appearance.save'),
];
