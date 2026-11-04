<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

final class UserStatus
{
    public const ACTIVE = 'active';
    public const BLOCKED = 'blocked';
    public const PENDING = 'pending';
    public const ARCHIVED = 'archived';

    public const ALL = [self::ACTIVE, self::BLOCKED, self::PENDING, self::ARCHIVED];
}
