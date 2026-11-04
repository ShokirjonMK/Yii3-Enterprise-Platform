<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base for REST API v1 tests over HTTP (running stack, E2E_BASE_URL).
 */
abstract class ApiTestCase extends TestCase
{
    protected WebClient $http;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        $this->http = new WebClient();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** @return array{access_token:string, refresh_token?:string, scope:string} */
    protected function token(array $user, ?string $scope = null): array
    {
        $body = ['grant_type' => 'password', 'username' => $user['username'], 'password' => $user['password']];
        if ($scope !== null) {
            $body['scope'] = $scope;
        }
        if (($user['mfa_secret'] ?? null) !== null) {
            $body['mfa_code'] = Fixtures::totp((string) $user['mfa_secret']);
        }
        $r = $this->http->json('POST', '/api/v1/auth/token', $body);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        return $this->json($r)['data'];
    }

    protected function api(string $method, string $path, ?string $token, ?array $body = null, array $headers = []): ResponseInterface
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $this->http->json($method, $path, $body, $headers);
    }

    protected function json(ResponseInterface $r): array
    {
        $d = json_decode((string) $r->getBody(), true);
        self::assertIsArray($d, 'response is not JSON: ' . substr((string) $r->getBody(), 0, 300));
        return $d;
    }

    protected function assertError(ResponseInterface $r, int $status, string $code): array
    {
        self::assertSame($status, $r->getStatusCode(), (string) $r->getBody());
        self::assertStringStartsWith('application/json', $r->getHeaderLine('Content-Type'));
        $d = $this->json($r);
        self::assertSame($code, $d['error']['code'] ?? null, (string) $r->getBody());
        self::assertNotEmpty($d['error']['message']);
        self::assertNotEmpty($d['error']['correlation_id']);
        return $d['error'];
    }
}
