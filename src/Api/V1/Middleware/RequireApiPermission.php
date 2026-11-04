<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Api\V1\Http\ApiException;
use App\Api\V1\Http\ApiRequest;
use App\Platform\Access\AccessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-level authorization for API routes: the token must carry the OAuth scope AND the user must
 * hold the RBAC permission (both default deny). Application services re-check the permission.
 *
 *   Route::get('/projects')->middleware(RequireApiPermission::for('projects.view', 'projects.read'))
 */
final class RequireApiPermission implements MiddlewareInterface
{
    public function __construct(
        private readonly AccessService $access,
        private readonly string $permission,
        private readonly ?string $scope = null,
    ) {}

    public static function for(string $permission, ?string $scope = null): array
    {
        return ['class' => self::class, '__construct()' => ['permission' => $permission, 'scope' => $scope]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = ApiRequest::identity($request);
        if ($this->scope !== null && !$identity->hasScope($this->scope)) {
            throw new ApiException(403, 'insufficient_scope', sprintf('The access token does not grant the "%s" scope.', $this->scope));
        }
        $this->access->assert($identity->user->id, $this->permission);
        return $handler->handle($request);
    }
}
