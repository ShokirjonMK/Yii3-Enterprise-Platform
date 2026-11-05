<?php

declare(strict_types=1);

use App\Web\Home\HomeController;
use App\Web\Middleware\RequireLogin;
use Yiisoft\Router\Route;

/**
 * Home: public landing page "/" and the post-login redirect "/home".
 */
return [
    Route::get('/')->action([HomeController::class, 'index'])->name('web.home'),
    Route::get('/home')->middleware(RequireLogin::class)->action([HomeController::class, 'redirect'])->name('home.redirect'),
];
