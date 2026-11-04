<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

/**
 * Which login methods a user may use.
 *  - any:        local password and any enabled SSO provider linked to the account
 *  - local_only: password only (break-glass accounts)
 *  - sso_only:   SSO only; such accounts can never log in with a password, even when SSO is disabled
 */
final class AuthPolicy
{
    public const ANY = 'any';
    public const LOCAL_ONLY = 'local_only';
    public const SSO_ONLY = 'sso_only';

    public const ALL = [self::ANY, self::LOCAL_ONLY, self::SSO_ONLY];
}
