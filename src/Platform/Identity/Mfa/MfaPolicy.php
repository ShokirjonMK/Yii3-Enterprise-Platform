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
     * @param list<string> $requiredBy why 2FA is mandatory: "account" (the user's own "Require two-factor" flag,
     *                                 Admin → Users → edit) and/or role names whose policy requires it
     *                                 (Admin → Authentication → Two-factor → Policies by role)
     */
    public function __construct(
        public readonly bool $required,
        public readonly array $allowed,
        public readonly int $rememberDays,
        public readonly array $sources,
        public readonly array $requiredBy = [],
    ) {}

    public function allows(string $type): bool
    {
        return in_array($type, $this->allowed, true);
    }
}
