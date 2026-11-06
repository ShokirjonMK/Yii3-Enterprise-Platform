<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Api;

use App\Api\V1\Http\ApiRequest;
use App\Api\V1\Http\ApiResponder;
use App\Modules\MultiDb\Application\DatasetService;
use App\Modules\MultiDb\Domain\Dataset;
use App\Modules\MultiDb\Domain\Datasets;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\ValidationException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Multi-DB demo REST resource: /api/v1/multidb/{dataset} over MariaDB, PostgreSQL, MongoDB and ClickHouse.
 * One contract for every engine; the id format depends on the dataset (int, uuid, ObjectId).
 */
#[OA\Schema(schema: 'MultiDbRow', type: 'object', description: 'Fields depend on the dataset — see GET /api/v1/multidb (fields[]).', properties: [
    new OA\Property(property: 'id', type: 'string', description: 'int (products), uuid (kpis, orders, pageviews) or ObjectId (articles)'),
    new OA\Property(property: 'created_at', type: 'string', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', nullable: true),
])]
final class MultiDbController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly DatasetService $service,
        private readonly SettingsService $settings,
        private readonly CurrentRoute $route,
    ) {}

    #[OA\Get(
        path: '/api/v1/multidb',
        operationId: 'listMultiDbDatasets',
        summary: 'Datasets: connection, engine, field definitions, row count (multidb.view, scope multidb.read)',
        tags: ['Multi-DB demo'],
        responses: [
            new OA\Response(response: 200, description: 'Datasets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function datasets(): ResponseInterface
    {
        return $this->responder->data(array_map(static fn(array $o) => self::describe($o['dataset']) + [
            'available' => $o['ok'],
            'count' => $o['count'],
            'error' => $o['error'] === null ? null : 'unavailable',
        ], $this->service->overview(false)));
    }

    #[OA\Get(
        path: '/api/v1/multidb/{dataset}',
        operationId: 'listMultiDbRows',
        summary: 'List rows of a dataset (search, exact filters, sort, paging)',
        tags: ['Multi-DB demo'],
        parameters: [
            new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews'])),
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/q'),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Field name or created_at / updated_at; "-" prefix for descending', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter', in: 'query', required: false, style: 'deepObject', explode: true, description: 'filter[field]=value for the dataset\'s filter fields (e.g. filter[category]=books, filter[published]=1)', schema: new OA\Schema(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of rows', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MultiDbRow')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', ref: '#/components/schemas/Links'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $filters = ApiRequest::filters($request, $d->filters);
        $q = $filters['q'] ?? '';
        unset($filters['q']);
        $page = $this->service->search(
            $d,
            $q,
            $filters,
            ApiRequest::page($request),
            ApiRequest::perPage($request, (int) $this->settings->get('api.default_page_size')),
            ApiRequest::sort($request, array_combine($d->sortable(), $d->sortable())),
        );
        return $this->responder->collection($page, $request, static fn(array $r) => $r);
    }

    #[OA\Post(
        path: '/api/v1/multidb/{dataset}',
        operationId: 'createMultiDbRow',
        summary: 'Create a row (multidb.create, scope multidb.write)',
        tags: ['Multi-DB demo'],
        parameters: [new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews']))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', example: ['sku' => 'EL-2001', 'name' => 'Planshet', 'category' => 'electronics', 'price' => '3200000.00', 'stock' => 7, 'active' => true])),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MultiDbRow')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $body = ApiRequest::body($request);
        unset($body['id'], $body['created_at'], $body['updated_at']);
        $row = $this->service->create($d, $body);
        return $this->responder->data($row, 201, ['Location' => '/api/v1/multidb/' . $d->code . '/' . $row['id']]);
    }

    #[OA\Get(
        path: '/api/v1/multidb/{dataset}/stats',
        operationId: 'getMultiDbStats',
        summary: 'Aggregates computed by the dataset\'s own database (SUM / DISTINCT ON / $group / uniqExact …)',
        tags: ['Multi-DB demo'],
        parameters: [new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews']))],
        responses: [
            new OA\Response(response: 200, description: 'Statistics', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'value', type: 'string'),
            ]))])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function stats(): ResponseInterface
    {
        $d = $this->dataset();
        return $this->responder->data($this->service->store($d)->stats(), 200, [], ['dataset' => $d->code, 'connection' => $d->connection, 'engine' => $d->engineLabel()]);
    }

    #[OA\Get(
        path: '/api/v1/multidb/{dataset}/{id}',
        operationId: 'getMultiDbRow',
        summary: 'Get one row',
        tags: ['Multi-DB demo'],
        parameters: [new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews'])), new OA\Parameter(name: 'id', in: 'path', required: true, description: 'int, uuid or ObjectId depending on the dataset', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Row', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MultiDbRow')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function view(): ResponseInterface
    {
        return $this->responder->data($this->service->get($this->dataset(), $this->id()));
    }

    #[OA\Patch(
        path: '/api/v1/multidb/{dataset}/{id}',
        operationId: 'updateMultiDbRow',
        summary: 'Partial update: only the given fields are validated and changed (multidb.update, scope multidb.write)',
        tags: ['Multi-DB demo'],
        parameters: [new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews'])), new OA\Parameter(name: 'id', in: 'path', required: true, description: 'int, uuid or ObjectId depending on the dataset', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', example: ['stock' => 3])),
        responses: [
            new OA\Response(response: 200, description: 'Updated row', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MultiDbRow')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = ApiRequest::body($request);
        unset($body['id'], $body['created_at'], $body['updated_at']);
        if ($body === []) {
            throw ValidationException::single('_', 'Send at least one field to change.');
        }
        return $this->responder->data($this->service->update($this->dataset(), $this->id(), $body, true));
    }

    #[OA\Delete(
        path: '/api/v1/multidb/{dataset}/{id}',
        operationId: 'deleteMultiDbRow',
        summary: 'Delete a row (multidb.delete, scope multidb.write)',
        tags: ['Multi-DB demo'],
        parameters: [new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'kpis', 'orders', 'articles', 'pageviews'])), new OA\Parameter(name: 'id', in: 'path', required: true, description: 'int, uuid or ObjectId depending on the dataset', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function delete(): ResponseInterface
    {
        $this->service->delete($this->dataset(), $this->id());
        return $this->responder->noContent();
    }

    /** @return array<string,mixed> */
    public static function describe(Dataset $d): array
    {
        return [
            'code' => $d->code,
            'label' => $d->label,
            'connection' => $d->connection,
            'engine' => $d->engineLabel(),
            'table' => $d->table,
            'id_type' => $d->idType,
            'filters' => $d->filters,
            'sortable' => $d->sortable(),
            'fields' => array_map(static fn($f) => ['name' => $f->name, 'label' => $f->label, 'required' => $f->required] + $f->openApiType(), $d->fields),
        ];
    }

    private function dataset(): Dataset
    {
        return Datasets::get((string) $this->route->getArgument('dataset'));
    }

    private function id(): string
    {
        return (string) $this->route->getArgument('id');
    }
}
