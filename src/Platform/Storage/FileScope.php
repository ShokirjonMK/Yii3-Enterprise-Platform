<?php

declare(strict_types=1);

namespace App\Platform\Storage;

/** Tenant visibility for file access: cross-tenant actors (tenants.cross) see every file. */
final readonly class FileScope
{
    public function __construct(
        public ?string $tenantId,
        public bool $crossTenant = false,
    ) {}

    public static function all(): self
    {
        return new self(null, true);
    }
}
