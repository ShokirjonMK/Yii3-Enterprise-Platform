<?php

declare(strict_types=1);

namespace App\Modules\Projects\Admin;

use App\Modules\Projects\Application\ProjectService;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectPriority;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * Admin · Projects (sample business module): list with search/filter/sort/paging (HTMX), create/edit
 * with validation errors, detail with members and activity timeline, lifecycle transitions,
 * archive (confirm) and background CSV report.
 */
final class ProjectsController
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly Responder $responder,
        private readonly ProjectService $projects,
        private readonly CurrentUser $currentUser,
        private readonly CurrentRoute $route,
        private readonly ObjectStorage $storage,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $q = $request->getQueryParams();
        $filters = [
            'q' => self::str($q, 'q'),
            'status' => self::str($q, 'status'),
            'priority' => self::str($q, 'priority'),
            'include_archived' => self::str($q, 'include_archived'),
        ];
        if (self::str($q, 'mine') === '1') {
            $filters['member_id'] = $actor->id;
        }
        $page = $this->projects->search($actor, $filters, max(1, (int) ($q['page'] ?? 1)), self::PER_PAGE, self::str($q, 'sort') ?: null);
        return $this->responder->render('@modules/Projects/Admin/views/index', [
            'page' => $page,
            'filters' => $filters,
            'query' => array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'priority' => $filters['priority'],
                'include_archived' => $filters['include_archived'], 'mine' => self::str($q, 'mine'), 'sort' => self::str($q, 'sort')]),
            'counts' => $this->projects->countsByStatus($actor),
            'reports' => $this->projects->recentReports($actor),
            'statuses' => ProjectStatus::cases(),
            'priorities' => ProjectPriority::values(),
        ]);
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
        return $this->responder->redirect('admin.projects.view', ['id' => $project->id]);
    }

    public function view(): ResponseInterface
    {
        $actor = $this->actor();
        $project = $this->projects->get($actor, $this->id());
        return $this->responder->render('@modules/Projects/Admin/views/view', [
            'project' => $project,
            'members' => $this->projects->members($actor, $project->id),
            'activity' => $this->projects->activity($actor, $project->id),
            'canManage' => $this->projects->canManage($actor, $project),
            'transitions' => $project->status()->allowedTransitions(),
            'pendingApproval' => $this->projects->pendingArchiveApproval($project),
            'archiveNeedsApproval' => $this->projects->archiveNeedsApproval($project),
        ]);
    }

    public function editForm(): ResponseInterface
    {
        $project = $this->projects->get($this->actor(), $this->id());
        return $this->form($project, self::formValues($project), []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $input = $this->input($request);
        $version = (int) ($request->getParsedBody()['version'] ?? 0);
        try {
            $project = $this->projects->update($actor, $this->id(), $input, $version);
        } catch (ValidationException $e) {
            return $this->form($this->projects->get($actor, $this->id()), $input + ['version' => $version], $e->errors(), 422);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.projects.edit', ['id' => $this->id()]);
        }
        $this->responder->success('Project saved.');
        return $this->responder->redirect('admin.projects.view', ['id' => $project->id]);
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $project = $this->projects->changeStatus($this->actor(), $this->id(), (string) ($body['status'] ?? ''),
                isset($body['version']) ? (int) $body['version'] : null, trim((string) ($body['comment'] ?? '')));
            $this->responder->success('Status changed to ' . $project->status()->label() . '.');
        } catch (ConflictException | ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.projects.view', ['id' => $this->id()]);
    }

    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $outcome = $this->projects->archive($this->actor(), $this->id(), isset($body['version']) ? (int) $body['version'] : null, trim((string) ($body['comment'] ?? '')));
            $this->responder->success($outcome->isPending() ? 'Archive requested: waiting for approval.' : 'Project archived.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.projects.view', ['id' => $this->id()]);
    }

    public function addMember(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $this->projects->addMember($this->actor(), $this->id(), trim((string) ($body['login'] ?? '')), (string) ($body['role'] ?? 'member'));
            $this->responder->success('Member added.');
        } catch (ValidationException | ConflictException $e) {
            $this->responder->error($e->getMessage() === 'Validation failed.' ? implode(' ', array_merge(...array_values($e instanceof ValidationException ? $e->errors() : []))) : $e->getMessage());
        }
        return $this->responder->redirect('admin.projects.view', ['id' => $this->id()]);
    }

    public function removeMember(): ResponseInterface
    {
        try {
            $this->projects->removeMember($this->actor(), $this->id(), (string) $this->route->getArgument('userId'));
            $this->responder->success('Member removed.');
        } catch (ValidationException $e) {
            $this->responder->error(implode(' ', array_merge(...array_values($e->errors()))));
        }
        return $this->responder->redirect('admin.projects.view', ['id' => $this->id()]);
    }

    public function requestReport(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $r = $this->projects->requestReport($this->actor(), array_filter([
            'status' => self::str($body, 'status'),
            'priority' => self::str($body, 'priority'),
            'q' => self::str($body, 'q'),
        ]));
        $this->responder->success('Report generation queued.');
        return $this->responder->redirect('admin.projects.report', ['id' => $r['report_id']]);
    }

    public function report(): ResponseInterface
    {
        $report = $this->projects->report($this->actor(), $this->id());
        return $this->responder->render('@modules/Projects/Admin/views/report', [
            'report' => $report,
            'storageConfigured' => $this->storage->isConfigured(),
        ]);
    }

    public function download(): ResponseInterface
    {
        $report = $this->projects->report($this->actor(), $this->id());
        if ($report['status'] !== 'done' || $report['path'] === null) {
            throw new NotFoundException('The report is not ready.');
        }
        $csv = $this->storage->filesystem()->read((string) $report['path']);
        return $this->responder->download($csv, 'projects-report-' . substr((string) $report['id'], 0, 8) . '.csv', 'text/csv; charset=utf-8');
    }

    // ------------------------------------------------------------------ helpers

    private function form(?Project $project, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@modules/Projects/Admin/views/form', [
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'priorities' => ProjectPriority::values(),
        ], status: $status);
    }

    public static function formValues(Project $p): array
    {
        return [
            'code' => $p->code(), 'name' => $p->name(), 'description' => $p->description(), 'priority' => $p->priority()->value,
            'owner_id' => $p->ownerId(), 'budget' => $p->budget(), 'starts_on' => $p->startsOn(), 'due_on' => $p->dueOn(),
            'tags' => implode(', ', $p->tags()), 'version' => $p->version(),
        ];
    }

    private function input(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $in = [];
        foreach (['code', 'name', 'description', 'priority', 'owner_id', 'budget', 'starts_on', 'due_on', 'tags'] as $k) {
            if (array_key_exists($k, $body) && is_string($body[$k])) {
                $in[$k] = $body[$k];
            }
        }
        if (($in['owner_id'] ?? '') === '') {
            unset($in['owner_id']);
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
