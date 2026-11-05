<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Mfa\MfaManager;
use App\Platform\Identity\Mfa\MfaMethodType;
use App\Platform\Identity\Mfa\TrustedDevices;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * Account → Security: remove a second factor, choose the default method, forget remembered devices.
 *
 * Author: ShokirjonMK
 */
final class MfaMethodsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly MfaManager $mfa,
        private readonly TrustedDevices $devices,
        private readonly AuditLogger $audit,
    ) {}

    public function remove(CurrentRoute $route): ResponseInterface
    {
        $user = $this->user();
        try {
            $this->mfa->remove($user, (string) $route->getArgument('id'));
            $this->devices->revokeAll($user->id);
            $this->responder->success('The method was removed.');
        } catch (ConflictException|NotFoundException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('web.security');
    }

    public function preferred(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $type = (string) (((array) $request->getParsedBody())['method'] ?? '');
        if (in_array($type, array_column($this->mfa->usable($user), 'type'), true)) {
            $this->mfa->setPreferred($user->id, $type);
            $this->responder->success(sprintf('Default method: %s.', MfaMethodType::label($type)));
        }
        return $this->responder->redirect('web.security');
    }

    public function revokeDevice(CurrentRoute $route): ResponseInterface
    {
        $user = $this->user();
        if ($this->devices->revoke($user->id, (string) $route->getArgument('id'))) {
            $this->audit->security('auth.mfa.device_forgotten', 'success', [], 'info', $user->id);
            $this->responder->success('The device will ask for the second factor again.');
        }
        return $this->responder->redirect('web.security');
    }

    public function revokeAllDevices(): ResponseInterface
    {
        $user = $this->user();
        $n = $this->devices->revokeAll($user->id);
        $this->audit->security('auth.mfa.device_forgotten', 'success', ['count' => $n], 'info', $user->id);
        $this->responder->success('All remembered devices were forgotten.');
        return $this->responder->redirect('web.security');
    }

    private function user(): User
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $this->users->find($identity->user->id) ?? $identity->user;
    }
}
