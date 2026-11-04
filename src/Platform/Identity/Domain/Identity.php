<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use Yiisoft\Auth\IdentityInterface;

/**
 * Authenticated principal for yiisoft/user and yiisoft/auth.
 */
final readonly class Identity implements IdentityInterface
{
    /**
     * @param list<string> $scopes API token scopes (empty for interactive sessions = no scope restriction)
     */
    public function __construct(
        public User $user,
        public string $authMethod = 'session',
        public array $scopes = [],
        public ?string $tokenId = null,
    ) {}

    public function getId(): ?string
    {
        return $this->user->id;
    }

    public function hasScope(string $scope): bool
    {
        if ($this->authMethod === 'session' || in_array('*', $this->scopes, true)) {
            return true;
        }
        foreach ($this->scopes as $s) {
            if ($s === $scope || (str_ends_with($s, '.*') && str_starts_with($scope, substr($s, 0, -1)))) {
                return true;
            }
        }
        return false;
    }
}
