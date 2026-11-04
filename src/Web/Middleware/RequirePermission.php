<?php

declare(strict_types=1);

namespace App\Web\Middleware;

use App\Platform\Access\AccessService;
use App\Shared\Domain\Exception\AccessDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\User\CurrentUser;

/**
 * Route-level authorization (default deny). Use via {@see self::for()} in route definitions:
 *
 *   Route::get('/admin/users')->middleware(RequirePermission::for('users.view'))
 *
 * Application services re-check permissions themselves; this is defence in depth.
 */
final class RequirePermission implements MiddlewareInterface
{
    /** @param list<string> $anyOf */
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly AccessService $access,
        private readonly array $anyOf,
    ) {}

    public static function for(string ...$anyOf): array
    {
        return ['class' => self::class, '__construct()' => ['anyOf' => $anyOf]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->currentUser->getId();
        foreach ($this->anyOf as $permission) {
            if ($this->access->can($userId, $permission)) {
                return $handler->handle($request);
            }
        }
        $this->access->assert($userId, $this->anyOf[0] ?? 'admin.access');
        throw new AccessDeniedException();
    }
}
