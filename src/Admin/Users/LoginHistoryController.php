<?php

declare(strict_types=1);

namespace App\Admin\Users;

use App\Platform\Identity\Application\LoginHistory;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * 02 · Users & Organizations → Login history (/admin/users/login-history): sign-in attempts of all users with
 * filters (user, IP, result, method / second factor, period). Permission users.login_history (route + service);
 * administrators without tenants.cross see only their tenant. A user's own page: ?user=<id> (linked from the user).
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
        return $this->responder->render('@admin/Users/views/login-history', [
            'page' => $this->history->all($identity->user, $filters, (int) ($q['page'] ?? 1), is_string($q['sort'] ?? null) ? $q['sort'] : null),
            'filters' => $filters,
            'stats' => $this->history->stats($identity->user),
        ]);
    }
}
