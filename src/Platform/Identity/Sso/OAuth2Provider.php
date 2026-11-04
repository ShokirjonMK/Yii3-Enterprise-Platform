<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * Generic OAuth 2.0 Authorization Code (+PKCE) provider for IdPs without OpenID Connect
 * (identity is read from a userinfo-style endpoint). Prefer {@see OidcProvider} whenever the IdP supports OIDC.
 */
final class OAuth2Provider implements SsoProviderInterface
{
    public function __construct(private readonly ClientInterface $http) {}

    public function type(): string
    {
        return 'oauth2';
    }

    public function requiredConfig(): array
    {
        return ['authorize_url', 'token_url', 'userinfo_url', 'client_id', 'issuer'];
    }

    public function authorizationRequest(array $provider, string $redirectUri): array
    {
        $c = $provider['config'];
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $params = [
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $c['scope'] ?? '',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        $url = (string) $c['authorize_url'];
        return ['url' => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'state' => ['state' => $state, 'verifier' => $verifier]];
    }

    public function handleCallback(array $provider, array $query, array $state, string $redirectUri): ExternalIdentity
    {
        $c = $provider['config'];
        if (!isset($query['state'], $state['state']) || !hash_equals((string) $state['state'], (string) $query['state'])) {
            throw new SsoException('Invalid or expired sign-in state. Please try again.', 'invalid_state');
        }
        try {
            $token = json_decode((string) $this->http->request('POST', (string) $c['token_url'], [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => (string) ($query['code'] ?? ''),
                    'redirect_uri' => $redirectUri,
                    'client_id' => $c['client_id'],
                    'client_secret' => (string) ($provider['secret'] ?? ''),
                    'code_verifier' => (string) $state['verifier'],
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
            ])->getBody(), true);
            if (empty($token['access_token'])) {
                throw new SsoException('Token exchange failed.', 'token_exchange_failed');
            }
            $info = json_decode((string) $this->http->request('GET', (string) $c['userinfo_url'], [
                'headers' => ['Authorization' => 'Bearer ' . $token['access_token'], 'Accept' => 'application/json'],
                'timeout' => 10,
            ])->getBody(), true);
        } catch (GuzzleException) {
            throw new SsoException('The identity provider is unavailable.', 'provider_unavailable');
        }
        $sub = (string) ($info[$c['subject_claim'] ?? 'sub'] ?? $info['id'] ?? '');
        if ($sub === '') {
            throw new SsoException('The identity provider returned no subject.', 'identify_failed');
        }
        return new ExternalIdentity(
            issuer: (string) $c['issuer'],
            subject: $sub,
            email: isset($info[$c['email_claim'] ?? 'email']) ? (string) $info[$c['email_claim'] ?? 'email'] : null,
            emailVerified: ($info['email_verified'] ?? false) === true,
            name: isset($info[$c['name_claim'] ?? 'name']) ? (string) $info[$c['name_claim'] ?? 'name'] : null,
            username: isset($info[$c['username_claim'] ?? 'login']) ? (string) $info[$c['username_claim'] ?? 'login'] : null,
            claims: is_array($info) ? $info : [],
        );
    }

    public function testConnection(array $provider): array
    {
        try {
            $r = $this->http->request('GET', (string) $provider['config']['authorize_url'], ['timeout' => 8, 'http_errors' => false, 'allow_redirects' => false]);
            return ['status' => $r->getStatusCode() < 500 ? 'healthy' : 'degraded', 'details' => ['http_status' => $r->getStatusCode()]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }
}
