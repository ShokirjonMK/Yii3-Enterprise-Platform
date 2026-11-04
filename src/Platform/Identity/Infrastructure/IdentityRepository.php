<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure;

use App\Platform\Identity\Application\ApiTokenService;
use App\Platform\Identity\Domain\Identity;
use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Auth\IdentityWithTokenRepositoryInterface;

/**
 * Identity lookup for yiisoft/user (session) and yiisoft/auth (Bearer tokens).
 * Inactive, archived or deleted users never resolve to an identity.
 */
final class IdentityRepository implements IdentityRepositoryInterface, IdentityWithTokenRepositoryInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ApiTokenService $tokens,
    ) {}

    public function findIdentity(string $id): ?IdentityInterface
    {
        $user = $this->users->find($id);
        return $user !== null && $user->isActive() ? new Identity($user) : null;
    }

    public function findIdentityByToken(string $token, ?string $type = null): ?IdentityInterface
    {
        return $this->tokens->authenticate($token);
    }
}
