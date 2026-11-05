<?php

declare(strict_types=1);

namespace App\Admin\Notifications;

use App\Platform\Notifications\ChannelSettings;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 08 · Messaging & Notifications → channel provider settings (SMTP, Telegram bot, SMS gateway, Web Push VAPID).
 * Saving secrets requires notifications.manage + recent re-authentication; every change is versioned and audited.
 *
 * Author: ShokirjonMK
 */
final class ChannelSettingsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ChannelSettings $settings,
        private readonly CurrentUser $currentUser,
    ) {}

    public function form(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        return $this->responder->render('@admin/Notifications/views/channel-settings', [
            'code' => $code,
            'fields' => $this->settings->fields($code),
            'errors' => [],
        ]);
    }

    public function save(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        try {
            $n = $this->settings->save((string) $this->currentUser->getId(), $code, (array) $request->getParsedBody());
        } catch (ValidationException $e) {
            return $this->responder->render('@admin/Notifications/views/channel-settings', [
                'code' => $code, 'fields' => $this->settings->fields($code), 'errors' => $e->errors(),
            ], status: 422);
        }
        $this->responder->success($n > 0 ? sprintf('Saved (%d change(s)). Use "Check" to verify the connection.', $n) : 'Nothing changed.');
        return $this->responder->redirect('admin.notifications.settings', ['code' => $code]);
    }

    public function vapid(): ResponseInterface
    {
        $this->settings->generateVapid((string) $this->currentUser->getId());
        $this->responder->success('New VAPID key pair generated. Existing browser subscriptions must re-subscribe.');
        return $this->responder->redirect('admin.notifications.settings', ['code' => 'webpush']);
    }
}
