<?php

declare(strict_types=1);

namespace App\Modules\Projects\Web;

use App\Modules\Projects\Admin\ProjectsController as AdminProjects;
use App\Modules\Projects\Application\ProjectService;
use App\Modules\Projects\Domain\ProjectPriority;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * End-user Projects pages (web layout): "my projects" / all tenant projects, detail, create, edit,
 * lifecycle transitions. Same ProjectService rules as Admin and API.
 */
final class ProjectsController
{
    public const PER_PAGE = 12;

    public function __construct(
        private readonly Responder $responder,
        private readonly ProjectService $projects,
        private readonly CurrentUser $currentUser,
        private readonly CurrentRoute $route,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $q = $request->getQueryParams();
        $scope = ($q['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
        $filters = ['q' => self::str($q, 'q'), 'status' => self::str($q, 'status')];
        if ($scope === 'mine') {
            $filters['member_id'] = $actor->id;
        }
        $page = $this->projects->search($actor, $filters, max(1, (int) ($q['page'] ?? 1)), self::PER_PAGE, self::str($q, 'sort') ?: '-updated_at');
        return $this->responder->render('@modules/Projects/Web/views/index', [
            'page' => $page,
            'scope' => $scope,
            'filters' => $filters,
            'query' => array_filter(['scope' => $scope, 'q' => $filters['q'], 'status' => $filters['status']]),
            'statuses' => ProjectStatus::cases(),
        ], 'web');
    }

    public function view(): ResponseInterface
    {
        $actor = $this->actor();
        $project = $this->projects->get($actor, $this->id());
        return $this->responder->render('@modules/Projects/Web/views/view', [
            'project' => $project,
            'members' => $this->projects->members($actor, $project->id),
            'activity' => $this->projects->activity($actor, $project->id),
            'canManage' => $this->projects->canManage($actor, $project),
            'transitions' => array_values(array_filter($project->status()->allowedTransitions(), static fn(ProjectStatus $s) => $s !== ProjectStatus::Archived)),
        ], 'web');
    }

    public function createForm(): ResponseInterface
    {
        return $this->form(null, ['priority' => 'normal'], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);
        try {
            $project = $this->projects->create($this->actor(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors(), 422);
        }
        $this->responder->success('Project created.');
        return $this->responder->redirect('web.projects.view', ['id' => $project->id]);
    }

    public function editForm(): ResponseInterface
    {
        $project = $this->projects->get($this->actor(), $this->id());
        return $this->form($project, AdminProjects::formValues($project), []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $input = $this->input($request);
        $version = (int) (((array) $request->getParsedBody())['version'] ?? 0);
        try {
            $project = $this->projects->update($actor, $this->id(), $input, $version);
        } catch (ValidationException $e) {
            return $this->form($this->projects->get($actor, $this->id()), $input + ['version' => $version], $e->errors(), 422);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('web.projects.edit', ['id' => $this->id()]);
        }
        $this->responder->success('Project saved.');
        return $this->responder->redirect('web.projects.view', ['id' => $project->id]);
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $p = $this->projects->changeStatus($this->actor(), $this->id(), (string) ($body['status'] ?? ''), isset($body['version']) ? (int) $body['version'] : null);
            $this->responder->success('Status changed to ' . $p->status()->label() . '.');
        } catch (ConflictException|ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('web.projects.view', ['id' => $this->id()]);
    }

    private function form(?\App\Modules\Projects\Domain\Project $project, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@modules/Projects/Web/views/form', [
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'priorities' => ProjectPriority::values(),
        ], 'web', $status);
    }

    private function input(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $in = [];
        foreach (['code', 'name', 'description', 'priority', 'budget', 'starts_on', 'due_on', 'tags'] as $k) {
            if (array_key_exists($k, $body) && is_string($body[$k])) {
                $in[$k] = $body[$k];
            }
        }
        return $in;
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new NotFoundException();
        }
        return $identity->user;
    }

    private function id(): string
    {
        return (string) $this->route->getArgument('id');
    }

    private static function str(array $a, string $k): string
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
    }
}
