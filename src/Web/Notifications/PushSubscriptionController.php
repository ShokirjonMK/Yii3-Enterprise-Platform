<?php

declare(strict_types=1);

namespace App\Web\Notifications;

use App\Platform\Integrations\Push\PushSubscriptions;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * Web Push subscription endpoints for the signed-in user (session + CSRF via X-CSRF-Token header).
 *
 *   GET    /notifications/push/key           → {"publicKey": "<VAPID public key>"|null}
 *   POST   /notifications/push/subscribe     body: PushSubscription.toJSON()  → 201 {"id": "..."}
 *   POST   /notifications/push/unsubscribe   body: {"endpoint": "..."}        → 200 {"removed": n}
 */
final class PushSubscriptionController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly PushSubscriptions $subscriptions,
        private readonly SettingsService $settings,
    ) {}

    public function key(): ResponseInterface
    {
        $key = trim((string) ($this->settings->get('notifications.vapid_public_key') ?? ''));
        return $this->responder->json(['publicKey' => $key === '' ? null : $key]);
    }

    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->payload($request);
        try {
            $id = $this->subscriptions->save((string) $this->currentUser->getId(), $data, $request->getHeaderLine('User-Agent'));
        } catch (ValidationException $e) {
            return $this->responder->json(['error' => ['code' => 'validation_failed', 'details' => $e->errors()]], 422);
        }
        return $this->responder->json(['id' => $id], 201);
    }

    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->payload($request);
        $removed = $this->subscriptions->delete((string) $this->currentUser->getId(), (string) ($data['endpoint'] ?? ''));
        return $this->responder->json(['removed' => $removed]);
    }

    /** @return array<string,mixed> */
    private function payload(ServerRequestInterface $request): array
    {
        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            $raw = (string) $request->getBody();
            $json = strlen($raw) <= 16384 ? json_decode($raw, true) : null;
            return is_array($json) ? $json : [];
        }
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }
}
