<?php

declare(strict_types=1);

namespace App\Admin\Action\Infrastructure;

use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\InfrastructureService;
use App\Platform\Monitoring\HealthChecker;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * 15 · Infrastructure: runtime inventory, component health, PHP/OPcache, Kubernetes workloads.
 */
final class InfrastructureController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly InfrastructureService $infra,
        private readonly HealthChecker $health,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('admin/infrastructure/index', [
            'workers' => $this->infra->workers(),
            'summary' => $this->infra->workerSummary(),
            'runtime' => $this->infra->runtime(),
            'checks' => $this->health->checkAll(),
        ]);
    }

    /** Kubernetes panel (loaded lazily via HTMX so a slow API never blocks the page). */
    public function kubernetes(): ResponseInterface
    {
        return $this->responder->fragment('admin/infrastructure/_kubernetes', ['k8s' => $this->infra->kubernetes()]);
    }

    public function scale(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $deployment = (string) ($body['deployment'] ?? '');
        $replicas = filter_var($body['replicas'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($replicas === false) {
                throw ValidationException::single('replicas', 'Replicas must be an integer.');
            }
            $r = $this->infra->scale($this->actor(), $deployment, $replicas, (string) ($body['reason'] ?? ''));
            $this->responder->success(sprintf('Deployment %s scaled from %d to %d replicas.', $r['name'], $r['previous'], $r['replicas']));
        } catch (ValidationException $e) {
            $this->responder->error(implode(' ', array_merge(...array_values($e->errors()))));
        } catch (ExternalServiceException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.infrastructure');
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
