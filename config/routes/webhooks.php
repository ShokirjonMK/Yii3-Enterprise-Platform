<?php

declare(strict_types=1);

use App\Platform\Integrations\Webhook\WebhookReceiveAction;
use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * Inbound webhooks (public, machine-to-machine): no session, no CSRF — every delivery is authenticated by an
 * HMAC-SHA256 signature over "timestamp.body" (see docs/INTEGRATIONS.md). Domain errors → JSON envelope.
 *
 * NOTE: this file must be listed in config/configuration.php 'routes' (followed by `composer yii-config-rebuild`).
 */
return [
    Group::create('/webhooks')
        ->middleware(DomainErrorMiddleware::json())
        ->routes(
            Route::post('/{code:[a-z0-9][a-z0-9_-]{1,63}}')->action(WebhookReceiveAction::class)->name('webhooks.receive'),
        ),
];
