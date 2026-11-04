<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

/**
 * A pluggable identity provider protocol adapter (OIDC, OneID, …).
 * Adapters are stateless; per-login state (state/nonce/PKCE) is passed in and out explicitly.
 */
interface SsoProviderInterface
{
    public function type(): string;

    /**
     * @param array<string,mixed> $provider decrypted provider config row
     * @return array{url:string, state:array<string,mixed>} authorization URL + data to keep in session
     */
    public function authorizationRequest(array $provider, string $redirectUri): array;

    /**
     * @param array<string,mixed> $provider
     * @param array<string,string> $query callback query parameters
     * @param array<string,mixed> $state data stored at authorizationRequest()
     */
    public function handleCallback(array $provider, array $query, array $state, string $redirectUri): ExternalIdentity;

    /**
     * @param array<string,mixed> $provider
     * @return array{status:string, details:array<string,mixed>}
     */
    public function testConnection(array $provider): array;

    /** @return list<string> required config keys (validated before enabling) */
    public function requiredConfig(): array;
}
