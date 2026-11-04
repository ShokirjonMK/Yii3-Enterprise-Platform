<?php

declare(strict_types=1);

/**
 * Template: API controller with OpenAPI attributes (src/Api/V1/Action/<Module>/<Module>Controller.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->hasStatus();
$props = [];
$props[] = "        new OA\\Property(property: 'id', type: 'string', format: 'uuid'),";
$props[] = "        new OA\\Property(property: 'tenant_id', type: 'string', format: 'uuid'),";
foreach ($m->fields as $f) {
    $props[] = '        ' . FieldCode::oaProperty($f) . ',';
}
foreach ($m->ofType('user') as $f) {
    $props[] = "        new OA\\Property(property: '{$f->name}_name', type: 'string', nullable: true, description: 'List responses only'),";
}
if ($status) {
    $props[] = "        new OA\\Property(property: 'allowed_transitions', type: 'array', items: new OA\\Items(type: 'string')),";
}
array_push(
    $props,
    "        new OA\\Property(property: 'version', type: 'integer'),",
    "        new OA\\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),",
    "        new OA\\Property(property: 'created_at', type: 'string', format: 'date-time'),",
    "        new OA\\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),",
);
$inputProps = [];
foreach ($m->editable() as $f) {
    $inputProps[] = '        ' . FieldCode::oaProperty($f, true) . ',';
}
$inputProps[] = "        new OA\\Property(property: 'version', type: 'integer', description: 'Alternative to If-Match on PATCH'),";
$required = array_map($q, array_map(static fn($f) => $f->name, array_filter($m->editable(), static fn($f) => $f->required && $f->type !== 'user')));
$filterParams = [];
foreach ($m->filterable() as $f) {
    $filterParams[] = "            new OA\\Parameter(name: 'filter[{$f->name}]', in: 'query', required: false, description: " . $q($f->type === 'enum' ? 'Comma-separated values' : 'Exact match') . ", schema: new OA\\Schema(type: 'string')),";
}
$sortKeys = array_merge(array_map(static fn($f) => $f->name, array_filter($m->fields, static fn($f) => $f->isSortable())), ['created_at', 'updated_at']);

$statusAction = $status ? <<<'PHP'

    #[OA\Post(
        path: '/api/v1/{{slug}}/{id}/status',
        operationId: 'change{{Entity}}Status',
        summary: 'Lifecycle transition ({{code}}.update, scope {{code}}.write)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: __STATES__),
            new OA\Property(property: 'comment', type: 'string', maxLength: 500),
            new OA\Property(property: 'version', type: 'integer'),
        ])),
        tags: ['{{label}}'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: '{{entityLabel}} after the transition', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/{{Entity}}')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
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
        return $this->one($this->service->changeStatus($actor, $this->id(), $status, ApiRequest::expectedVersion($request, $body, false), $comment));
    }
PHP : '';
$statusAction = str_replace('__STATES__', '[' . implode(', ', array_map($q, $status ? $m->statusField()->options : [])) . ']', $statusAction);

$uses = [
    'use App\Api\V1\Http\ApiRequest;',
    'use App\Api\V1\Http\ApiResponder;',
    'use App\Modules\{{Module}}\Application\{{Entity}}Service;',
    'use App\Modules\{{Module}}\Domain\{{Entity}};',
];
if ($status) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
$uses[] = 'use App\Modules\{{Module}}\Infrastructure\{{Entity}}Repository;';
$uses[] = 'use App\Platform\Settings\SettingsService;';
if ($status) {
    $uses[] = 'use App\Shared\Domain\Exception\ValidationException;';
}
array_push($uses, 'use OpenApi\Attributes as OA;', 'use Psr\Http\Message\ResponseInterface;', 'use Psr\Http\Message\ServerRequestInterface;', 'use Yiisoft\Router\CurrentRoute;');

$userNames = array_map(static fn($f) => "            " . $q($f->name . '_name') . " => \$r[" . $q($f->name . '_name') . "] ?? null,", $m->ofType('user'));

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Api\V1\Action\{{Module}};

__USES__

/**
 * {{label}} resource ({{flag}}). All rules live in {{Entity}}Service; this class is the HTTP mapping
 * (JSON envelope, ETag = version, If-Match for updates, standard error shape).
 */
#[OA\Schema(schema: '{{Entity}}', type: 'object', properties: [
__PROPS__
])]
#[OA\Schema(schema: '{{Entity}}Input', type: 'object', required: [__REQUIRED__], properties: [
__INPUT_PROPS__
])]
final class {{Module}}Controller
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly {{Entity}}Service $service,
        private readonly SettingsService $settings,
        private readonly CurrentRoute $route,
    ) {}

    #[OA\Get(
        path: '/api/v1/{{slug}}',
        operationId: 'list{{Module}}',
        summary: 'List {{labelLower}} ({{code}}.view, scope {{code}}.read)',
        tags: ['{{label}}'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/q'),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: __SORT_DOC__, schema: new OA\Schema(type: 'string', example: '-created_at')),
__FILTER_PARAMS__
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of {{labelLower}}', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/{{Entity}}')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', ref: '#/components/schemas/Links'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $page = $this->service->search(
            $actor,
            ApiRequest::filters($request, {{Entity}}Repository::FILTERS),
            ApiRequest::page($request),
            ApiRequest::perPage($request, (int) $this->settings->get('api.default_page_size')),
            ApiRequest::sort($request, {{Entity}}Repository::SORTABLE),
        );
        return $this->responder->collection($page, $request, [self::class, 'presentRow']);
    }

    #[OA\Post(
        path: '/api/v1/{{slug}}',
        operationId: 'create{{Entity}}',
        summary: 'Create a {{entityLabelLower}} ({{code}}.create, scope {{code}}.write)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/{{Entity}}Input')),
        tags: ['{{label}}'],
        responses: [
            new OA\Response(response: 201, description: 'Created', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string')), new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/{{Entity}}')])),
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
        $body = ApiRequest::body($request);
        unset($body['status'], $body['version']);
        $e = $this->service->create($actor, $body);
        return $this->responder->data(self::present($e), 201, [
            'Location' => '/api/v1/{{slug}}/' . $e->id,
            'ETag' => ApiRequest::etag($e->version()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/{{slug}}/{id}',
        operationId: 'get{{Entity}}',
        summary: 'Get a {{entityLabelLower}} (ETag = version)',
        tags: ['{{label}}'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: '{{entityLabel}}', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/{{Entity}}')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        return $this->one($this->service->get(ApiRequest::identity($request)->user, $this->id()));
    }

    #[OA\Patch(
        path: '/api/v1/{{slug}}/{id}',
        operationId: 'update{{Entity}}',
        summary: 'Update a {{entityLabelLower}} ({{code}}.update; If-Match or body version required)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/{{Entity}}Input')),
        tags: ['{{label}}'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/{{Entity}}')])),
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
        return $this->one($this->service->update($actor, $this->id(), $body, $version));
    }

    #[OA\Delete(
        path: '/api/v1/{{slug}}/{id}',
        operationId: 'delete{{Entity}}',
        summary: 'Delete (soft) a {{entityLabelLower}} ({{code}}.delete, scope {{code}}.write)',
        tags: ['{{label}}'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $this->service->delete($actor, $this->id(), ApiRequest::expectedVersion($request, [], false));
        return $this->responder->noContent();
    }
__STATUS_ACTION__
    // ------------------------------------------------------------------ presenters

    public static function present({{Entity}} $e): array
    {
        return $e->toArray()__PRESENT_TRANSITIONS__;
    }

    /** Collection row (repository search row incl. joined user names). */
    public static function presentRow(array $r): array
    {
        return self::present({{Entity}}::fromRow($r)) + [
__USER_NAMES__
        ];
    }

    private function one({{Entity}} $e): ResponseInterface
    {
        return $this->responder->data(self::present($e), 200, ['ETag' => ApiRequest::etag($e->version())]);
    }

    private function id(): string
    {
        return (string) $this->route->getArgument('id');
    }
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__PROPS__' => implode("\n", $props),
    '__REQUIRED__' => implode(', ', $required),
    '__INPUT_PROPS__' => implode("\n", $inputProps),
    '__SORT_DOC__' => $q('One of ' . implode(', ', $sortKeys) . '; prefix "-" for descending. Default -created_at.'),
    '__FILTER_PARAMS__' => implode("\n", $filterParams),
    '__STATUS_ACTION__' => $statusAction,
    '__PRESENT_TRANSITIONS__' => $status ? " + [\n            'allowed_transitions' => array_map(static fn({{Entity}}Status \$s) => \$s->value, \$e->status()->allowedTransitions()),\n        ]" : '',
    '__USER_NAMES__' => implode("\n", $userNames),
]);
