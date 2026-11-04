<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

/**
 * Verified identity asserted by an external provider. Linking key = (issuer, subject).
 */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $username,
        public array $claims,
    ) {}
}
