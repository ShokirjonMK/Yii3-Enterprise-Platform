<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

/**
 * Effective two-factor policy of one user (merged from the policies of all their roles).
 *
 * Author: ShokirjonMK
 */
final class MfaPolicy
{
    /**
     * @param list<string> $allowed   methods the user may enrol / use (already filtered by globally enabled + configured)
     * @param list<string> $sources   policy subjects that contributed (role names or "*")
     */
    public function __construct(
        public readonly bool $required,
        public readonly array $allowed,
        public readonly int $rememberDays,
        public readonly array $sources,
    ) {}

    public function allows(string $type): bool
    {
        return in_array($type, $this->allowed, true);
    }
}
