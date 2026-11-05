<?php

declare(strict_types=1);

namespace App\Web\Action\Account;

use App\Platform\Appearance\ThemeService;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * Account → Appearance: choose a personal theme or follow the organisation theme.
 *
 * Author: ShokirjonMK
 */
final class AppearanceController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly ThemeService $themes,
    ) {}

    public function show(): ResponseInterface
    {
        $user = $this->identity()->user;
        return $this->responder->render('web/account/appearance', [
            'themes' => $this->themes->registry()->all(),
            'allowed' => $this->themes->userChoiceAllowed(),
            'choice' => $this->themes->userChoice($user->id),
            'organisation' => $this->themes->resolve($user->tenantId, null),
        ], 'web');
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->identity()->user;
        $code = (string) (((array) $request->getParsedBody())['theme'] ?? '');
        try {
            $this->themes->chooseForUser($user->id, $code === '' ? null : $code);
            $this->responder->success($code === '' ? 'You follow the organisation theme.' : 'Your theme has been changed.');
        } catch (AccessDeniedException|NotFoundException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('web.appearance');
    }

    private function identity(): Identity
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $identity;
    }
}
