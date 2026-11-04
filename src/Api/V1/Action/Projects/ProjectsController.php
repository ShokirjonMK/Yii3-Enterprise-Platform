<?php

declare(strict_types=1);

namespace App\Api\V1\Action\Projects;

use App\Api\V1\Http\ApiRequest;
use App\Api\V1\Http\ApiResponder;
use App\Modules\Projects\Application\ProjectService;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Infrastructure\ProjectRepository;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\ValidationException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Projects resource (module.projects). All rules live in ProjectService; this is the HTTP mapping.
 */
final class ProjectsController
{
    public const FILTERS = ['status', 'priority', 'owner_id', 'tag', 'tenant_id', 'include_archived'];

    public function __construct(
        private readonly ApiResponder $responder,
        private readonly ProjectService $projects,
        private readonly SettingsService $settings,
        private readonly CurrentRoute $route,
    ) {}

    #[OA\Get(
        path: '/api/v1/projects',
        operationId: 'listProjects',
        summary: 'List projects (projects.view, scope projects.read)',
        description: 'Archived projects are excluded unless filter[status] includes "archived" or filter[include_archived]=1. q = full-text (code, name, description) + substring search.',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/q'),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'One of code, name, status, priority, budget, starts_on, due_on, created_at, updated_at; prefix "-" for descending. Default -created_at.', schema: new OA\Schema(type: 'string', example: '-created_at')),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, description: 'Comma-separated statuses', schema: new OA\Schema(type: 'string', example: 'active,on_hold')),
            new OA\Parameter(name: 'filter[priority]', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'high,critical')),
            new OA\Parameter(name: 'filter[owner_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'filter[tag]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[include_archived]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of projects', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Project')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                new OA\Property(property: 'links', ref: '#/components/schemas/Links'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $page = $this->projects->search(
            $actor,
            ApiRequest::filters($request, self::FILTERS),
            ApiRequest::page($request),
            ApiRequest::perPage($request, (int) $this->settings->get('api.default_page_size')),
            ApiRequest::sort($request, ProjectRepository::SORTABLE),
        );
        return $this->responder->collection($page, $request, [self::class, 'presentRow']);
    }

    #[OA\Post(
        path: '/api/v1/projects',
        operationId: 'createProject',
        summary: 'Create a project (projects.create, scope projects.write)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectInput')),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 201, description: 'Created (status draft)', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string')), new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project')])),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/UnsupportedMediaType', response: 415),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $project = $this->projects->create($actor, ApiRequest::body($request));
        return $this->responder->data(self::present($project), 201, [
            'Location' => '/api/v1/projects/' . $project->id,
            'ETag' => ApiRequest::etag($project->version()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/projects/{id}',
        operationId: 'getProject',
        summary: 'Get a project (ETag = version)',
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: 'Project', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        return $this->one($this->projects->get($actor, $this->id()));
    }

    #[OA\Patch(
        path: '/api/v1/projects/{id}',
        operationId: 'updateProject',
        summary: 'Update a project (owner or projects.manage_all; If-Match or body version required)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectInput')),
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
            new OA\Response(ref: '#/components/responses/PreconditionRequired', response: 428),
        ],
    )]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $body = ApiRequest::body($request);
        $version = (int) ApiRequest::expectedVersion($request, $body, true);
        unset($body['version'], $body['status'], $body['tenant_id']);
        return $this->one($this->projects->update($actor, $this->id(), $body, $version));
    }

    #[OA\Delete(
        path: '/api/v1/projects/{id}',
        operationId: 'archiveProject',
        summary: 'Archive a project (projects.delete). Allowed from draft or completed.',
        description: 'With the workflow "project.lifecycle" enabled, archiving a completed project creates an approval request (approvals.decide, four-eyes) and returns 202 with meta.approval; the project is archived once approved.',
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: 'Archived project', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project')])),
            new OA\Response(response: 202, description: 'Approval requested (meta.approval.id)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $version = ApiRequest::expectedVersion($request, [], false);
        return $this->archived($this->projects->archive($actor, $this->id(), $version));
    }

    private function archived(\App\Modules\Projects\Application\ArchiveOutcome $outcome): ResponseInterface
    {
        if (!$outcome->isPending()) {
            return $this->one($outcome->project);
        }
        return $this->responder->data(
            self::present($outcome->project),
            202,
            ['ETag' => ApiRequest::etag($outcome->project->version())],
            ['approval' => ['id' => $outcome->approvalId, 'status' => 'pending', 'transition' => 'archive']],
        );
    }

    #[OA\Post(
        path: '/api/v1/projects/{id}/status',
        operationId: 'changeProjectStatus',
        summary: 'Lifecycle transition (draft→active needs projects.approve; →archived needs projects.delete)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['active', 'on_hold', 'completed', 'archived']),
            new OA\Property(property: 'comment', type: 'string', maxLength: 500),
            new OA\Property(property: 'version', type: 'integer'),
        ])),
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: 'Project after transition', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Project')])),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $body = ApiRequest::body($request);
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        if ($status === '') {
            throw ValidationException::single('status', 'status is required.');
        }
        $comment = is_string($body['comment'] ?? null) ? mb_substr(trim($body['comment']), 0, 500) : '';
        $version = ApiRequest::expectedVersion($request, $body, false);
        if ($status === ProjectStatus::Archived->value) {
            return $this->archived($this->projects->archive($actor, $this->id(), $version, $comment));
        }
        return $this->one($this->projects->changeStatus($actor, $this->id(), $status, $version, $comment));
    }

    #[OA\Post(
        path: '/api/v1/projects/reports',
        operationId: 'requestProjectsReport',
        summary: 'Enqueue a CSV summary report for the caller tenant (background job)',
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'priority', type: 'string'),
            new OA\Property(property: 'q', type: 'string'),
        ])),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 202, description: 'Accepted', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Report')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function report(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $r = $this->projects->requestReport($actor, ApiRequest::body($request));
        return $this->responder->data(
            self::presentReport($this->projects->report($actor, $r['report_id'])),
            202,
            ['Location' => '/api/v1/projects/reports/' . $r['report_id']],
        );
    }

    #[OA\Get(
        path: '/api/v1/projects/reports/{id}',
        operationId: 'getProjectsReport',
        summary: 'Report status',
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Report')])),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function reportStatus(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        return $this->responder->data(self::presentReport($this->projects->report($actor, $this->id())));
    }

    // ------------------------------------------------------------------ presenters

    public static function present(Project $p): array
    {
        return $p->toArray() + [
            'allowed_transitions' => array_map(static fn(ProjectStatus $s) => $s->value, $p->status()->allowedTransitions()),
        ];
    }

    public static function presentRow(array $r): array
    {
        $status = ProjectStatus::tryFrom((string) $r['status']);
        return [
            'id' => $r['id'],
            'tenant_id' => $r['tenant_id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'description' => $r['description'],
            'status' => $r['status'],
            'priority' => $r['priority'],
            'owner_id' => $r['owner_id'],
            'owner_name' => $r['owner_name'] ?? null,
            'budget' => $r['budget'] === null ? null : number_format((float) $r['budget'], 2, '.', ''),
            'starts_on' => $r['starts_on'],
            'due_on' => $r['due_on'],
            'tags' => array_values((array) $r['tags']),
            'version' => (int) $r['version'],
            'allowed_transitions' => $status === null ? [] : array_map(static fn(ProjectStatus $s) => $s->value, $status->allowedTransitions()),
            'created_by' => $r['created_by'],
            'created_at' => self::iso($r['created_at']),
            'updated_at' => self::iso($r['updated_at']),
            'archived_at' => self::iso($r['archived_at']),
        ];
    }

    public static function presentReport(array $r): array
    {
        return [
            'id' => $r['id'],
            'status' => $r['status'],
            'job_id' => $r['job_id'],
            'job_status' => $r['job_status'] ?? null,
            'rows' => $r['rows_count'] === null ? null : (int) $r['rows_count'],
            'size_bytes' => $r['size_bytes'] === null ? null : (int) $r['size_bytes'],
            'path' => $r['path'],
            'error' => $r['error'],
            'created_at' => self::iso($r['created_at']),
            'finished_at' => self::iso($r['finished_at']),
        ];
    }

    private function one(Project $p): ResponseInterface
    {
        return $this->responder->data(self::present($p), 200, ['ETag' => ApiRequest::etag($p->version())]);
    }

    private function id(): string
    {
        return (string) $this->route->getArgument('id');
    }

    private static function iso(mixed $v): ?string
    {
        return $v === null ? null : (new \DateTimeImmutable((string) $v))->format(DATE_ATOM);
    }
}
