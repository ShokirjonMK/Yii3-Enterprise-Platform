<?php

declare(strict_types=1);

namespace App\Admin\Action\DevTools;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Gii\NextSteps\NextStepsRunner;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * Admin · Developer tools · Enterprise Gii → Next steps (/admin/devtools/gii/next-steps): runs migrate:up,
 * rbac:sync and Kafka topic provisioning from the panel and shows every step's status, exit code, duration and
 * the full console output live (HTMX polling every second while the run is active). Same availability as Gii
 * (404 in production / flag off); starting a run requires a recent step-up authentication.
 *
 * Author: ShokirjonMK
 */
final class GiiNextStepsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly FeatureFlagService $flags,
        private readonly NextStepsRunner $runner,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        return $this->responder->render('admin/gii/next-steps', [
            'state' => $this->runner->state(),
            'runs' => $this->runner->recent(15),
            'module' => trim((string) ($request->getQueryParams()['module'] ?? '')),
            'errors' => [],
        ]);
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        $b = (array) $request->getParsedBody();
        $steps = array_values(array_filter((array) ($b['steps'] ?? []), 'is_string'));
        $module = preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) ($b['module'] ?? '')) ?? '';
        try {
            $run = $this->runner->start((string) $this->currentUser->getId(), $module !== '' ? $module : 'manual', $steps);
        } catch (ValidationException $e) {
            return $this->responder->render('admin/gii/next-steps', [
                'state' => $this->runner->state(), 'runs' => $this->runner->recent(15), 'module' => $module, 'errors' => $e->errors(),
            ], status: 422);
        } catch (ConflictException $e) {
            $this->responder->error('Another run is in progress — wait for it to finish.');
            $active = $this->runner->active();
            return $active !== null ? $this->responder->redirect('admin.devtools.gii.runs.view', ['id' => $active]) : $this->responder->redirect('admin.devtools.gii.steps');
        }
        if ($run['mode'] === 'inline') {
            // no queue worker is alive: run in this request (the page then shows the complete log)
            $this->runner->execute($run['id']);
        }
        return $this->responder->redirect('admin.devtools.gii.runs.view', ['id' => $run['id']]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $this->guard();
        return $this->responder->render('admin/gii/run', ['run' => $this->load($route)]);
    }

    /** HTMX fragment polled every second while the run is queued/running. */
    public function live(CurrentRoute $route): ResponseInterface
    {
        $this->guard();
        return $this->responder->fragment('admin/gii/_run-live', ['run' => $this->load($route)]);
    }

    private function load(CurrentRoute $route): array
    {
        return $this->runner->find((string) $route->getArgument('id')) ?? throw new NotFoundException('Run not found.');
    }

    private function guard(): void
    {
        if (!GiiController::available($this->flags)) {
            throw new NotFoundException('Page not found.');
        }
    }
}
