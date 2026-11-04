<?php

declare(strict_types=1);

namespace App\Admin\Action\Security;

use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Monitoring\AsvsChecklist;
use App\Platform\Monitoring\SecurityCenter;
use App\Platform\Monitoring\SecurityPosture;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\WebAuthSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 18 · Security Center: posture, security events, sign-in failures, lockouts, sessions, API tokens, ASVS.
 */
final class SecurityController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly SecurityPosture $posture,
        private readonly SecurityCenter $center,
        private readonly WebAuthSession $webAuth,
    ) {}

    public function index(): ResponseInterface
    {
        $actor = $this->actor();
        $checks = $this->posture->checks();
        return $this->responder->render('admin/security/index', [
            'checks' => $checks,
            'summary' => SecurityPosture::summarize($checks),
            'noMfa' => $this->posture->privilegedWithoutMfa(),
            'events' => $this->center->recentEvents($actor),
            'byIp' => $this->center->failuresByIp(),
            'byUser' => $this->center->failuresByUser($actor),
            'locked' => $this->center->lockedUsers($actor),
        ]);
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $filters = ['q' => mb_substr(trim((string) ($q['q'] ?? '')), 0, 100)];
        $page = $this->center->activeSessions($this->actor(), $filters, (int) ($q['page'] ?? 1), (string) ($q['sort'] ?? ''));
        $sid = $this->webAuth->sessionId();
        return $this->responder->render('admin/security/sessions', [
            'page' => $page,
            'filters' => $filters,
            'current' => $sid === null ? null : SessionRegistry::hash($sid),
        ]);
    }

    public function asvs(): ResponseInterface
    {
        return $this->responder->render('admin/security/asvs', ['asvs' => AsvsChecklist::default()->load()]);
    }

    public function revokeSession(CurrentRoute $route): ResponseInterface
    {
        $sid = $this->webAuth->sessionId();
        $this->center->revokeSession($this->actor(), (string) $route->getArgument('id'), $sid === null ? null : SessionRegistry::hash($sid));
        $this->responder->success('The session has been terminated.');
        return $this->responder->redirect('admin.security.sessions');
    }

    public function revokeUserSessions(CurrentRoute $route): ResponseInterface
    {
        $n = $this->center->revokeAllSessions($this->actor(), (string) $route->getArgument('id'), $this->webAuth->sessionId());
        $this->responder->success(sprintf('%d session(s) terminated.', $n));
        return $this->responder->redirect('admin.security.sessions');
    }

    public function revokeTokens(CurrentRoute $route): ResponseInterface
    {
        $n = $this->center->revokeApiTokens($this->actor(), (string) $route->getArgument('id'));
        $this->responder->success(sprintf('%d API token(s) revoked.', $n));
        return $this->responder->redirect('admin.security.sessions');
    }

    public function unlock(CurrentRoute $route): ResponseInterface
    {
        $this->center->unlock($this->actor(), (string) $route->getArgument('id'));
        $this->responder->success('The account has been unlocked.');
        return $this->responder->redirect('admin.security');
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException();
        }
        return $identity->user;
    }
}
