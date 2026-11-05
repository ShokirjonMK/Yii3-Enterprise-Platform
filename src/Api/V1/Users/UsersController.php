<?php

declare(strict_types=1);

namespace App\Api\V1\Users;

use App\Api\V1\Http\ApiRequest;
use App\Api\V1\Http\ApiResponder;
use App\Platform\Access\AccessService;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserStatus;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\Database\Sql;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Users resource (thin layer over UserService: RBAC, tenant isolation, audit, optimistic locking).
 */
final class UsersController
{
    public const FILTERS = ['status', 'role', 'tenant_id', 'organization_id'];

    public function __construct(
        private readonly ApiResponder $responder,
        private readonly UserService $users,
        private readonly AccessService $access,
        private readonly SettingsService $settings,
        private readonly CurrentRoute $route,
    ) {}

    #[OA\Get(
        path: '/api/v1/users',
        operationId: 'listUsers',
        summary: 'List users (users.view, scope users.read)',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/q'),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['username', '-username', 'full_name', '-full_name', 'email', '-email', 'status', '-status', 'created_at', '-created_at', 'last_login_at', '-last_login_at'])),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'blocked', 'pending', 'archived'])),
            new OA\Parameter(name: 'filter[role]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[tenant_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of users', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                new OA\Property(property: 'links', ref: '#/components/schemas/Links'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $page = $this->users->list(
            $actor,
            ApiRequest::filters($request, self::FILTERS),
            ApiRequest::page($request),
            ApiRequest::perPage($request, (int) $this->settings->get('api.default_page_size')),
            ApiRequest::sort($request, UserRepository::SORTABLE),
        );
        return $this->responder->collection($page, $request, static fn(array $r): array => [
            'id' => $r['id'],
            'tenant_id' => $r['tenant_id'],
            'tenant_name' => $r['tenant_name'],
            'organization_id' => $r['organization_id'],
            'username' => $r['username'],
            'email' => $r['email'],
            'phone' => $r['phone'],
            'full_name' => $r['full_name'],
            'status' => $r['status'],
            'auth_policy' => $r['auth_policy'],
            'mfa_enabled' => Sql::bool($r['mfa_enabled']),
            'roles' => $r['roles'] === null || $r['roles'] === '' ? [] : explode(',', (string) $r['roles']),
            'last_login_at' => self::iso($r['last_login_at']),
            'created_at' => self::iso($r['created_at']),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/users/{id}',
        operationId: 'getUser',
        summary: 'Get a user (ETag = version)',
        tags: ['Users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: 'User', headers: [new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        return $this->one($this->users->get($actor, $this->id()));
    }

    #[OA\Post(
        path: '/api/v1/users',
        operationId: 'createUser',
        summary: 'Create a user (users.manage, scope users.write)',
        description: 'When no password is given (and the policy allows passwords) a one-time temporary password is generated and returned once in meta.temporary_password.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UserInput')),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/UnsupportedMediaType', response: 415),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        $result = $this->users->create($actor, ApiRequest::body($request));
        /** @var User $user */
        $user = $result['user'];
        return $this->responder->data(
            $this->present($user),
            201,
            ['Location' => '/api/v1/users/' . $user->id, 'ETag' => ApiRequest::etag($user->version)],
            $result['temporaryPassword'] !== null ? ['temporary_password' => $result['temporaryPassword']] : null,
        );
    }

    #[OA\Patch(
        path: '/api/v1/users/{id}',
        operationId: 'updateUser',
        summary: 'Update a user (optimistic locking: If-Match or body version)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UserInput')),
        tags: ['Users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id'), new OA\Parameter(ref: '#/components/parameters/If-Match')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
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
        $version = ApiRequest::expectedVersion($request, $body, true);
        unset($body['version'], $body['password']);
        return $this->one($this->users->update($actor, $this->id(), $body, $version));
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/block',
        operationId: 'blockUser',
        summary: 'Block a user (revokes sessions and tokens)',
        tags: ['Users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: 'Blocked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function block(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        return $this->one($this->users->setStatus($actor, $this->id(), UserStatus::BLOCKED));
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/unblock',
        operationId: 'unblockUser',
        summary: 'Unblock a user',
        tags: ['Users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/id')],
        responses: [
            new OA\Response(response: 200, description: 'Active again', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function unblock(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ApiRequest::identity($request)->user;
        return $this->one($this->users->setStatus($actor, $this->id(), UserStatus::ACTIVE));
    }

    private function one(User $user): ResponseInterface
    {
        return $this->responder->data($this->present($user), 200, ['ETag' => ApiRequest::etag($user->version)]);
    }

    private function present(User $user): array
    {
        return $user->toPublicArray() + ['roles' => $this->access->rolesOf($user->id), 'version' => $user->version];
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
