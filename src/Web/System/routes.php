<?php

declare(strict_types=1);

use App\Web\Action\System\HealthController;
use App\Web\Action\System\MetricsAction;
use Yiisoft\Router\Route;

/**
 * Operational endpoints: no session, no CSRF.
 *  /health/live   — process is up (liveness probe)
 *  /health/ready  — critical dependencies reachable (readiness probe); details only with metrics token
 *  /metrics       — Prometheus exposition (Bearer METRICS_TOKEN or private network)
 */
return [
    Route::get('/health/live')->action([HealthController::class, 'live'])->name('system.health.live'),
    Route::get('/health/ready')->action([HealthController::class, 'ready'])->name('system.health.ready'),
    Route::get('/metrics')->action(MetricsAction::class)->name('system.metrics'),
    // design theme stylesheets (immutable per ?v=<hash>; built-in from themes/, uploaded from the database)
    Route::get('/appearance/{code:[a-z0-9][a-z0-9-]{1,39}}.css')->action([\App\Web\Action\System\ThemeCssAction::class, 'css'])->name('system.theme.css'),
];
