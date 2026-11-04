<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\Fixtures;

/**
 * Token endpoint (password grant incl. MFA, throttling/lockout), refresh rotation + reuse detection,
 * revocation, /me, 401 handling.
 */
final class ApiAuthTest extends ApiTestCase
{
    public function testPasswordGrantAndMe(): void
    {
        $u = Fixtures::user(['user']);
        $t = $this->token($u, 'projects.read profile');
        self::assertStringStartsWith('yep_a_', $t['access_token']);
        self::assertStringStartsWith('yep_r_', $t['refresh_token']);
        self::assertSame('projects.read profile', $t['scope']);

        $r = $this->api('GET', '/api/v1/me', $t['access_token']);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $d = $this->json($r)['data'];
        self::assertSame($u['username'], $d['user']['username']);
        self::assertContains('user', $d['roles']);
        self::assertContains('projects.view', $d['permissions']);
        self::assertSame(['projects.read', 'profile'], $d['scopes']);
        self::assertArrayNotHasKey('password_hash', $d['user']);
        self::assertSame('', $r->getHeaderLine('Set-Cookie'), 'the API is cookie-free');
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
    }

    public function testInvalidCredentialsAreGeneric(): void
    {
        $u = Fixtures::user(['user']);
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => $u['username'], 'password' => 'wrong-Passw0rd!']), 401, 'invalid_grant');
        $e = $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => 'nobody-' . bin2hex(random_bytes(3)), 'password' => 'x']), 401, 'invalid_grant');
        self::assertSame('Invalid username or password.', $e['message']);
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'magic']), 400, 'unsupported_grant_type');
        $e = $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password']), 422, 'validation_failed');
        self::assertArrayHasKey('username', $e['details']);
        self::assertArrayHasKey('password', $e['details']);
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => $u['username'], 'password' => $u['password'], 'scope' => 'root']), 400, 'invalid_scope');
    }

    public function testMfaIsRequiredForMfaAccounts(): void
    {
        $u = Fixtures::user(['user'], mfa: true);
        $base = ['grant_type' => 'password', 'username' => $u['username'], 'password' => $u['password']];
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, $base), 401, 'mfa_required');
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, $base + ['mfa_code' => '000000']), 401, 'invalid_mfa_code');
        $r = $this->api('POST', '/api/v1/auth/token', null, $base + ['mfa_code' => Fixtures::totp((string) $u['mfa_secret'])]);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        // TOTP replay protection: the same code cannot be used twice
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, $base + ['mfa_code' => Fixtures::totp((string) $u['mfa_secret'])]), 401, 'invalid_mfa_code');
    }

    public function testMandatoryMfaWithoutEnrolmentIsRefused(): void
    {
        $u = Fixtures::user(['platform_admin']);
        $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => $u['username'], 'password' => $u['password']]), 403, 'mfa_enrollment_required');
    }

    public function testLockoutAfterRepeatedFailures(): void
    {
        $u = Fixtures::user(['user']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertError($this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => $u['username'], 'password' => 'Wrong-' . $i]), 401, 'invalid_grant');
        }
        // even the correct password is refused while the account is locked
        $r = $this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'password', 'username' => $u['username'], 'password' => $u['password']]);
        $this->assertError($r, 429, 'too_many_requests');
        self::assertGreaterThanOrEqual(60, (int) $r->getHeaderLine('Retry-After'));
    }

    public function testFormEncodedTokenRequestIsAccepted(): void
    {
        $u = Fixtures::user(['user']);
        $r = $this->http->post('/api/v1/auth/token', ['grant_type' => 'password', 'username' => $u['username'], 'password' => $u['password']], ['Accept' => 'application/json'], false);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
    }

    public function testRefreshRotationAndReuseDetection(): void
    {
        $u = Fixtures::user(['user']);
        $first = $this->token($u);
        $r = $this->api('POST', '/api/v1/auth/refresh', null, ['refresh_token' => $first['refresh_token']]);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $second = $this->json($r)['data'];
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        // rotation revoked the previous access token, the new one works
        $this->assertError($this->api('GET', '/api/v1/me', $first['access_token']), 401, 'unauthorized');
        self::assertSame(200, $this->api('GET', '/api/v1/me', $second['access_token'])->getStatusCode());

        // replaying the rotated refresh token = theft: the whole family is revoked
        $this->assertError($this->api('POST', '/api/v1/auth/refresh', null, ['refresh_token' => $first['refresh_token']]), 401, 'invalid_grant');
        $this->assertError($this->api('GET', '/api/v1/me', $second['access_token']), 401, 'unauthorized');
        $this->assertError($this->api('POST', '/api/v1/auth/refresh', null, ['refresh_token' => $second['refresh_token']]), 401, 'invalid_grant');
    }

    public function testRevoke(): void
    {
        $t = $this->token(Fixtures::user(['user']));
        self::assertSame(204, $this->api('POST', '/api/v1/auth/revoke', null, ['token' => $t['access_token']])->getStatusCode());
        $this->assertError($this->api('GET', '/api/v1/me', $t['access_token']), 401, 'unauthorized');
        $this->assertError($this->api('POST', '/api/v1/auth/refresh', null, ['refresh_token' => $t['refresh_token']]), 401, 'invalid_grant');
    }

    public function testMissingOrInvalidTokenIs401WithChallenge(): void
    {
        $r = $this->api('GET', '/api/v1/me', null);
        $this->assertError($r, 401, 'unauthorized');
        self::assertStringContainsString('Bearer', $r->getHeaderLine('WWW-Authenticate'));
        $this->assertError($this->api('GET', '/api/v1/projects', 'yep_a_' . str_repeat('x', 43)), 401, 'unauthorized');
        $this->assertError($this->api('GET', '/api/v1/users', null, null, ['Authorization' => 'Basic Zm9vOmJhcg==']), 401, 'unauthorized');
    }

    public function testClientCredentialsGrant(): void
    {
        $r = $this->api('POST', '/api/v1/auth/token', null, ['grant_type' => 'client_credentials', 'client_id' => 'nope-' . bin2hex(random_bytes(3)), 'client_secret' => 'wrong']);
        // ApiClientService (identity work package) present → invalid_client; absent → unsupported_grant_type
        self::assertContains($r->getStatusCode(), [400, 401], $this->http->body());
        self::assertContains($this->json($r)['error']['code'], ['invalid_client', 'unsupported_grant_type']);
    }
}
