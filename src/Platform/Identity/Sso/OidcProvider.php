<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * OpenID Connect Authorization Code flow with PKCE (RFC 7636) — corporate SSO (Keycloak, ADFS,
 * Azure AD/Entra ID, Okta, Authentik…).
 *
 * Security checks:
 *  - state (CSRF) bound to the browser session and single-use,
 *  - nonce bound to the ID token (replay),
 *  - PKCE S256,
 *  - ID token signature verified against the provider JWKS (RS/ES/PS algs only; "none" and HS* rejected),
 *  - iss == discovered issuer, aud contains client_id, azp == client_id when multiple audiences,
 *  - exp / nbf / iat with 60 s leeway.
 */
final class OidcProvider implements SsoProviderInterface
{
    private const ALLOWED_ALGS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512', 'PS256', 'PS384', 'PS512'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CacheInterface $cache,
    ) {}

    public function type(): string
    {
        return 'oidc';
    }

    public function requiredConfig(): array
    {
        return ['issuer', 'client_id'];
    }

    public function authorizationRequest(array $provider, string $redirectUri): array
    {
        $meta = $this->discover($provider);
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random(48);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $scopes = $provider['config']['scopes'] ?? 'openid profile email';

        $params = [
            'response_type' => 'code',
            'client_id' => $provider['config']['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => is_array($scopes) ? implode(' ', $scopes) : $scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if (!empty($provider['config']['prompt'])) {
            $params['prompt'] = $provider['config']['prompt'];
        }
        $sep = str_contains($meta['authorization_endpoint'], '?') ? '&' : '?';
        return [
            'url' => $meta['authorization_endpoint'] . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'state' => ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier],
        ];
    }

    public function handleCallback(array $provider, array $query, array $state, string $redirectUri): ExternalIdentity
    {
        if (isset($query['error'])) {
            throw new SsoException('The identity provider returned an error: ' . mb_substr((string) $query['error'], 0, 100), 'provider_error');
        }
        if (!isset($query['state'], $state['state']) || !hash_equals((string) $state['state'], (string) $query['state'])) {
            throw new SsoException('Invalid or expired sign-in state. Please try again.', 'invalid_state');
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new SsoException('Authorization code is missing.', 'missing_code');
        }
        $meta = $this->discover($provider);
        $clientId = (string) $provider['config']['client_id'];
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => (string) $state['verifier'],
            'client_id' => $clientId,
        ];
        $options = ['form_params' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 10, 'connect_timeout' => 5];
        $secret = $provider['secret'] ?? null;
        if ($secret !== null && $secret !== '') {
            if (($provider['config']['token_auth'] ?? 'client_secret_basic') === 'client_secret_post') {
                $options['form_params']['client_secret'] = $secret;
            } else {
                $options['auth'] = [rawurlencode($clientId), rawurlencode($secret)];
            }
        }
        try {
            $response = $this->http->request('POST', $meta['token_endpoint'], $options);
        } catch (GuzzleException $e) {
            throw new SsoException('Token exchange with the identity provider failed.', 'token_exchange_failed');
        }
        $tokens = json_decode((string) $response->getBody(), true);
        if (!is_array($tokens) || !isset($tokens['id_token'])) {
            throw new SsoException('The identity provider did not return an ID token.', 'no_id_token');
        }

        $claims = $this->validateIdToken((string) $tokens['id_token'], $meta, $clientId, (string) $state['nonce']);

        if (!empty($provider['config']['use_userinfo']) && isset($meta['userinfo_endpoint'], $tokens['access_token'])) {
            try {
                $info = json_decode((string) $this->http->request('GET', $meta['userinfo_endpoint'], [
                    'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Accept' => 'application/json'], 'timeout' => 10,
                ])->getBody(), true);
                if (is_array($info) && ($info['sub'] ?? null) === $claims['sub']) {
                    $claims += $info;
                }
            } catch (Throwable) {
                // userinfo is optional enrichment
            }
        }

        $usernameClaim = (string) ($provider['config']['username_claim'] ?? 'preferred_username');
        return new ExternalIdentity(
            issuer: (string) $claims['iss'],
            subject: (string) $claims['sub'],
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: ($claims['email_verified'] ?? false) === true,
            name: isset($claims['name']) ? (string) $claims['name'] : (trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? '')) ?: null),
            username: isset($claims[$usernameClaim]) ? (string) $claims[$usernameClaim] : null,
            claims: $claims,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function validateIdToken(string $idToken, array $meta, string $clientId, string $expectedNonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new SsoException('Malformed ID token.', 'invalid_id_token');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        $alg = $header['alg'] ?? '';
        if (!in_array($alg, self::ALLOWED_ALGS, true)) {
            throw new SsoException('ID token uses a forbidden signing algorithm.', 'invalid_id_token');
        }
        $jwks = $this->jwks($meta['jwks_uri']);
        JWT::$leeway = 60;
        try {
            $decoded = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, $alg));
        } catch (Throwable $e) {
            // Key rotation: refresh JWKS once and retry
            $jwks = $this->jwks($meta['jwks_uri'], true);
            try {
                $decoded = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, $alg));
            } catch (Throwable $e2) {
                throw new SsoException('ID token signature or lifetime is invalid.', 'invalid_id_token');
            }
        }
        $claims = json_decode((string) json_encode($decoded), true);

        if (($claims['iss'] ?? null) !== $meta['issuer']) {
            throw new SsoException('ID token issuer mismatch.', 'invalid_issuer');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (!in_array($clientId, $aud, true)) {
            throw new SsoException('ID token audience mismatch.', 'invalid_audience');
        }
        if (count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new SsoException('ID token authorized party mismatch.', 'invalid_azp');
        }
        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new SsoException('ID token has no expiry.', 'invalid_id_token');
        }
        if (!isset($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new SsoException('ID token nonce mismatch (possible replay).', 'invalid_nonce');
        }
        if (!isset($claims['sub']) || $claims['sub'] === '') {
            throw new SsoException('ID token has no subject.', 'invalid_id_token');
        }
        return $claims;
    }

    public function testConnection(array $provider): array
    {
        try {
            $meta = $this->discover($provider, true);
            $jwks = $this->jwks($meta['jwks_uri'], true);
            return ['status' => 'healthy', 'details' => [
                'issuer' => $meta['issuer'],
                'authorization_endpoint' => $meta['authorization_endpoint'],
                'token_endpoint' => $meta['token_endpoint'],
                'keys' => count($jwks['keys'] ?? []),
                'pkce_s256' => in_array('S256', $meta['code_challenge_methods_supported'] ?? ['S256'], true),
            ]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    /** @return array<string,mixed> */
    public function discover(array $provider, bool $refresh = false): array
    {
        $issuer = rtrim((string) ($provider['config']['issuer'] ?? ''), '/');
        $url = (string) ($provider['config']['discovery_url'] ?? '') ?: $issuer . '/.well-known/openid-configuration';
        if (!str_starts_with($url, 'https://') && empty($provider['config']['allow_insecure_http'])) {
            throw new SsoException('Discovery URL must use HTTPS.', 'insecure_discovery');
        }
        $key = 'oidc.meta.' . sha1($url);
        if (!$refresh && is_array($cached = $this->cache->get($key))) {
            return $cached;
        }
        try {
            $meta = json_decode((string) $this->http->request('GET', $url, ['timeout' => 10, 'connect_timeout' => 5, 'headers' => ['Accept' => 'application/json']])->getBody(), true);
        } catch (GuzzleException $e) {
            throw new SsoException('OIDC discovery failed: ' . $e->getMessage(), 'discovery_failed');
        }
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (!is_array($meta) || empty($meta[$required])) {
                throw new SsoException("OIDC discovery document lacks \"$required\".", 'discovery_invalid');
            }
        }
        if ($issuer !== '' && rtrim($meta['issuer'], '/') !== $issuer) {
            throw new SsoException('Discovered issuer does not match the configured issuer.', 'discovery_invalid');
        }
        $this->cache->set($key, $meta, 3600);
        return $meta;
    }

    /** @return array{keys:list<array<string,mixed>>} */
    private function jwks(string $uri, bool $refresh = false): array
    {
        $key = 'oidc.jwks.' . sha1($uri);
        if (!$refresh && is_array($cached = $this->cache->get($key))) {
            return $cached;
        }
        $jwks = json_decode((string) $this->http->request('GET', $uri, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']])->getBody(), true);
        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new SsoException('Invalid JWKS document.', 'jwks_invalid');
        }
        $this->cache->set($key, $jwks, 3600);
        return $jwks;
    }

    private static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
