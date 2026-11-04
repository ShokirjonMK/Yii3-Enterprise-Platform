<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Identity\Domain\User;

final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public string $method,
        public bool $mfaRequired,
        public bool $mfaSetupRequired,
        public bool $mustChangePassword,
    ) {}
}
