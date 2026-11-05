<?php

declare(strict_types=1);

namespace App\Admin\Deployments;

use App\Platform\Deployments\DeploymentService;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Infrastructure\ExternalServiceException;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 16 · CI/CD & Deployments: registry, request → four-eyes approval → GitLab pipeline, rollback.
 */
final class DeploymentsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly DeploymentService $deployments,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $d = $this->deployments->get((string) $route->getArgument('id'));
        return $this->responder->render('@admin/Deployments/views/view', [
            'd' => $d,
            'history' => (new Query($this->db))->select(['occurred_at', 'action', 'result', 'actor_name', 'metadata'])->from('audit_log')
                ->where(['entity_type' => 'deployment', 'entity_id' => $d['id']])->orderBy(['occurred_at' => SORT_ASC])->limit(100)->all(),
            'actorId' => $this->actor()->id,
        ]);
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $id = $this->deployments->request($this->actor(), $body);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors(), $body, 422);
        } catch (ConflictException $e) {
            return $this->renderIndex($request, ['environment' => [$e->getMessage()]], $body, 409);
        }
        $this->responder->success('Deployment requested. A second person with deploy.approve must approve it.');
        return $this->responder->redirect('admin.deployments.view', ['id' => $id]);
    }

    public function approve(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $result = $this->deployments->approve($this->actor(), $id, (string) (((array) $request->getParsedBody())['comment'] ?? ''));
        $result['triggered'] ? $this->responder->success('Approved. ' . $result['message']) : $this->responder->info('Approved. ' . $result['message']);
        return $this->responder->redirect('admin.deployments.view', ['id' => $id]);
    }

    public function reject(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->deployments->reject($this->actor(), $id, (string) (((array) $request->getParsedBody())['reason'] ?? ''));
            $this->responder->success('Deployment rejected.');
        } catch (ValidationException $e) {
            $this->responder->error('A reason is required to reject a deployment.');
        }
        return $this->responder->redirect('admin.deployments.view', ['id' => $id]);
    }

    public function trigger(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $result = $this->deployments->trigger($id, $this->actor());
        $result['triggered'] ? $this->responder->success($result['message']) : $this->responder->error($result['message']);
        return $this->responder->redirect('admin.deployments.view', ['id' => $id]);
    }

    public function refresh(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $status = $this->deployments->refresh($id, $this->actor());
            $this->responder->info('Status: ' . $status);
        } catch (ExternalServiceException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.deployments.view', ['id' => $id]);
    }

    public function rollback(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $newId = $this->deployments->requestRollback($this->actor(), (string) $route->getArgument('id'), (string) (((array) $request->getParsedBody())['notes'] ?? ''));
        $this->responder->success('Rollback requested. It needs four-eyes approval like any deployment.');
        return $this->responder->redirect('admin.deployments.view', ['id' => $newId]);
    }

    private function renderIndex(ServerRequestInterface $request, array $errors = [], array $old = [], int $status = 200): ResponseInterface
    {
        $q = $request->getQueryParams();
        $filters = ['environment' => (string) ($q['environment'] ?? ''), 'status' => (string) ($q['status'] ?? '')];
        return $this->responder->render('@admin/Deployments/views/index', [
            'page' => $this->deployments->list($filters, (int) ($q['page'] ?? 1), (string) ($q['sort'] ?? '')),
            'filters' => $filters,
            'current' => $this->deployments->current(),
            'gitlab' => $this->deployments->pipelines(10),
            'errors' => $errors,
            'old' => $old,
        ], status: $status);
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
