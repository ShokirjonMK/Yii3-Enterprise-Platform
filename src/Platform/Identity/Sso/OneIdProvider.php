<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * OneID (Yagona identifikatsiya tizimi, id.egov.uz) adapter.
 *
 * OneID is an OAuth 2.0–style protocol with custom grant/response types (it is NOT OpenID Connect:
 * there is no ID token, nonce, PKCE or JWKS). The flow implemented here follows the integration
 * specification issued to connected organisations:
 *
 *   1. Browser → {endpoint}?response_type=one_code&client_id&redirect_uri&scope&state
 *   2. Callback ?code&state                         (state is verified, single use)
 *   3. POST {endpoint} grant_type=one_authorization_code&client_id&client_secret&code&redirect_uri → access_token
 *   4. POST {endpoint} grant_type=one_access_token_identify&client_id&client_secret&access_token&scope → user data
 *
 * The endpoint URL, client id/secret and scope are issued by the operator per organisation and are
 * configured in Admin → Authentication; nothing is hard-coded. Status: UNVERIFIED against the live
 * service until real credentials are configured (see docs/SSO.md).
 *
 * Subject (linking key) = PINFL (`pin`), the stable personal identifier. It is stored only in
 * user_identities.subject; claims kept for audit have the PINFL masked.
 */
final class OneIdProvider implements SsoProviderInterface
{
    public function __construct(private readonly ClientInterface $http) {}

    public function type(): string
    {
        return 'oneid';
    }

    public function requiredConfig(): array
    {
        return ['endpoint', 'client_id', 'scope'];
    }

    public function authorizationRequest(array $provider, string $redirectUri): array
    {
        $endpoint = $this->endpoint($provider);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $params = [
            'response_type' => 'one_code',
            'client_id' => $provider['config']['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $provider['config']['scope'],
            'state' => $state,
        ];
        return ['url' => $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'state' => ['state' => $state]];
    }

    public function handleCallback(array $provider, array $query, array $state, string $redirectUri): ExternalIdentity
    {
        if (!isset($query['state'], $state['state']) || !hash_equals((string) $state['state'], (string) $query['state'])) {
            throw new SsoException('Invalid or expired sign-in state. Please try again.', 'invalid_state');
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new SsoException('OneID did not return an authorization code.', 'missing_code');
        }
        $endpoint = $this->endpoint($provider);
        $secret = (string) ($provider['secret'] ?? '');

        $token = $this->post($endpoint, [
            'grant_type' => 'one_authorization_code',
            'client_id' => $provider['config']['client_id'],
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        if (empty($token['access_token'])) {
            throw new SsoException('OneID token exchange failed.', 'token_exchange_failed');
        }

        $data = $this->post($endpoint, [
            'grant_type' => 'one_access_token_identify',
            'client_id' => $provider['config']['client_id'],
            'client_secret' => $secret,
            'access_token' => $token['access_token'],
            'scope' => $provider['config']['scope'],
        ]);
        $valid = $data['valid'] ?? null;
        if ($valid === false || $valid === 'false' || empty($data['pin'])) {
            throw new SsoException('OneID could not identify the user.', 'identify_failed');
        }
        $pin = (string) $data['pin'];
        if (!preg_match('/^\d{14}$/', $pin)) {
            throw new SsoException('OneID returned an invalid personal identifier.', 'identify_failed');
        }

        $claims = $data;
        $claims['pin'] = substr($pin, 0, 2) . str_repeat('*', 10) . substr($pin, -2);
        unset($claims['sess_id']);

        $name = trim((string) ($data['full_name'] ?? trim(($data['sur_name'] ?? '') . ' ' . ($data['first_name'] ?? '') . ' ' . ($data['mid_name'] ?? ''))));
        return new ExternalIdentity(
            issuer: 'oneid:' . parse_url($endpoint, PHP_URL_HOST),
            subject: $pin,
            email: !empty($data['email']) ? (string) $data['email'] : null,
            emailVerified: false,
            name: $name !== '' ? $name : null,
            username: !empty($data['user_id']) ? (string) $data['user_id'] : null,
            claims: $claims,
        );
    }

    public function testConnection(array $provider): array
    {
        try {
            $endpoint = $this->endpoint($provider);
            $response = $this->http->request('GET', $endpoint, ['timeout' => 8, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false]);
            $code = $response->getStatusCode();
            return ['status' => $code < 500 ? 'healthy' : 'degraded', 'details' => [
                'endpoint' => $endpoint,
                'http_status' => $code,
                'note' => 'Reachability only — a full login requires issued client credentials.',
            ]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function endpoint(array $provider): string
    {
        $endpoint = (string) ($provider['config']['endpoint'] ?? '');
        if (!str_starts_with($endpoint, 'https://')) {
            throw new SsoException('OneID endpoint must be configured with an https:// URL.', 'not_configured');
        }
        return $endpoint;
    }

    /** @return array<string,mixed> */
    private function post(string $endpoint, array $form): array
    {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'form_params' => $form,
                'timeout' => 10,
                'connect_timeout' => 5,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException) {
            throw new SsoException('OneID service request failed.', 'provider_unavailable');
        }
        $json = json_decode((string) $response->getBody(), true);
        return is_array($json) ? $json : [];
    }
}
