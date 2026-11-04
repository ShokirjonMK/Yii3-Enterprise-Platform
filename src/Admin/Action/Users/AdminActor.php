<?php

declare(strict_types=1);

namespace App\Admin\Action\Users;

use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\AccessDeniedException;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * Small helpers shared by the identity admin controllers (sections 02–06).
 */
final class AdminActor
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function user(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException();
        }
        return $identity->user;
    }

    /** @return array<string,mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        unset($body['_csrf']);
        return $body;
    }

    /** Only scalar query values (prevents array injection into filters). @param list<string> $keys */
    public static function query(ServerRequestInterface $request, array $keys): array
    {
        $out = [];
        $params = $request->getQueryParams();
        foreach ($keys as $k) {
            $v = $params[$k] ?? '';
            $out[$k] = is_scalar($v) ? trim((string) $v) : '';
        }
        return $out;
    }

    public static function page(ServerRequestInterface $request): int
    {
        $p = $request->getQueryParams()['page'] ?? 1;
        return is_scalar($p) ? max(1, (int) $p) : 1;
    }
}
