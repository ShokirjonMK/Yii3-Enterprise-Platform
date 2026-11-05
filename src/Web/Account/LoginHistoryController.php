<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Platform\Identity\Application\LoginHistory;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * Account → Login history (/account/login-history): the signed-in user's own sign-ins — successful and failed (also
 * failed attempts by somebody else on this account), with time, IP, device, sign-in method and second factor.
 *
 * Author: ShokirjonMK
 */
final class LoginHistoryController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly LoginHistory $history,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException('Sign in first.');
        }
        $q = $request->getQueryParams();
        $filters = LoginHistory::filters($q);
        return $this->responder->render('@web/Account/views/login-history', [
            'page' => $this->history->mine($identity->user, $filters, (int) ($q['page'] ?? 1), is_string($q['sort'] ?? null) ? $q['sort'] : null),
            'filters' => $filters,
            'stats' => $this->history->stats($identity->user, $identity->user->id),
            'canViewAll' => $this->history->mayViewAll($identity->user),
        ], 'web');
    }
}
