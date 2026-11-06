<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\V1\Middleware\RateLimitMiddleware;
use App\Platform\Settings\SettingsService;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use Predis\ClientInterface;

/**
 * Cross-cutting API behaviour: envelope/status codes (404/405/415/400), rate limiting headers + 429,
 * CORS preflight, statelessness, OpenAPI document and Swagger UI page.
 */
final class ApiPlatformTest extends ApiTestCase
{
    private const ORIGIN = 'https://cors-test.example';

    public function testHealthIsPublicAndStateless(): void
    {
        $r = $this->api('GET', '/api/v1/health', null);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['data' => ['status' => 'ok']], $this->json($r));
        self::assertStringStartsWith('application/json', $r->getHeaderLine('Content-Type'));
        self::assertSame('', $r->getHeaderLine('Set-Cookie'));
        self::assertNotSame('', $r->getHeaderLine('X-Request-Id'));
    }

    public function testErrorStatusCodes(): void
    {
        $this->assertError($this->api('GET', '/api/v1/does-not-exist', null), 404, 'not_found');
        $r = $this->api('DELETE', '/api/v1/health', null);
        $this->assertError($r, 405, 'method_not_allowed');
        self::assertStringContainsString('GET', $r->getHeaderLine('Allow'));
        $r = $this->api('PUT', '/api/v1/auth/token', null, ['x' => 1]);
        $this->assertError($r, 405, 'method_not_allowed');
        self::assertStringContainsString('POST', $r->getHeaderLine('Allow'));

        $r = $this->http->json('POST', '/api/v1/auth/token', null, ['Content-Type' => 'text/plain']);
        // empty body: reaches the action → 422; with a non-JSON body → 415
        $this->assertError($r, 422, 'validation_failed');
        $client = new \GuzzleHttp\Client(['base_uri' => getenv('E2E_BASE_URL') ?: 'http://nginx:8080', 'http_errors' => false]);
        $r = $client->post('/api/v1/projects', ['headers' => ['Content-Type' => 'text/xml'], 'body' => '<x/>']);
        $this->assertError($r, 415, 'unsupported_media_type');
        $r = $client->post('/api/v1/auth/token', ['headers' => ['Content-Type' => 'application/json'], 'body' => '{"grant_type":']);
        $this->assertError($r, 400, 'invalid_json');
    }

    public function testRateLimitHeadersAnd429(): void
    {
        $token = $this->token(Fixtures::user(['user']))['access_token'];
        $r = $this->api('GET', '/api/v1/me', $token);
        self::assertSame(200, $r->getStatusCode());
        $limit = (int) $r->getHeaderLine('X-RateLimit-Limit');
        self::assertGreaterThan(0, $limit);
        self::assertSame($limit - 1, (int) $r->getHeaderLine('X-RateLimit-Remaining'));
        self::assertGreaterThan(0, (int) $r->getHeaderLine('X-RateLimit-Reset'));

        // exhaust this token's window directly in Redis (same key the middleware uses)
        /** @var ClientInterface $redis */
        $redis = TestContainer::service(ClientInterface::class);
        $redis->set('rl:' . RateLimitMiddleware::tokenKey($token), (string) $limit, 'EX', 60);
        $r = $this->api('GET', '/api/v1/me', $token);
        $this->assertError($r, 429, 'too_many_requests');
        self::assertGreaterThan(0, (int) $r->getHeaderLine('Retry-After'));
        self::assertSame('0', $r->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame((string) $limit, $r->getHeaderLine('X-RateLimit-Limit'));
        $redis->del(['rl:' . RateLimitMiddleware::tokenKey($token)]);
    }

    public function testCorsPreflightAndSimpleRequests(): void
    {
        $settings = TestContainer::service(SettingsService::class);
        $before = (array) $settings->get('api.cors_origins');
        $super = Fixtures::user(['superadmin']);
        $settings->set($super['id'], 'api.cors_origins', array_values(array_unique([...$before, self::ORIGIN])), comment: 'API CORS test');
        sleep(3); // settings propagate to PHP-FPM workers within ≤ 2 s (APCu config revision cache)
        try {
            $r = $this->http->json('OPTIONS', '/api/v1/projects', null, ['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization, content-type']);
            self::assertSame(204, $r->getStatusCode());
            self::assertSame(self::ORIGIN, $r->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertStringContainsString('POST', $r->getHeaderLine('Access-Control-Allow-Methods'));
            self::assertStringContainsString('Authorization', $r->getHeaderLine('Access-Control-Allow-Headers'));
            self::assertSame('', $r->getHeaderLine('Access-Control-Allow-Credentials'), 'no credentials mode');

            $r = $this->http->json('GET', '/api/v1/health', null, ['Origin' => self::ORIGIN]);
            self::assertSame(self::ORIGIN, $r->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertStringContainsString('X-RateLimit-Remaining', $r->getHeaderLine('Access-Control-Expose-Headers'));

            // errors carry CORS headers too (so browsers can read them)
            $r = $this->http->json('GET', '/api/v1/me', null, ['Origin' => self::ORIGIN]);
            self::assertSame(401, $r->getStatusCode());
            self::assertSame(self::ORIGIN, $r->getHeaderLine('Access-Control-Allow-Origin'));

            // unknown origins get no CORS headers
            $r = $this->http->json('OPTIONS', '/api/v1/projects', null, ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET']);
            self::assertSame('', $r->getHeaderLine('Access-Control-Allow-Origin'));
            $r = $this->http->json('GET', '/api/v1/health', null, ['Origin' => 'https://evil.example']);
            self::assertSame('', $r->getHeaderLine('Access-Control-Allow-Origin'));
        } finally {
            $settings->set($super['id'], 'api.cors_origins', $before, comment: 'API CORS test cleanup');
        }
    }

    public function testOpenApiDocumentIsValid(): void
    {
        self::assertSame(401, $this->api('GET', '/api/v1/openapi.json', null)->getStatusCode(), 'the document is private');
        [$dev] = \App\Tests\Support\AdminSession::login(['developer']);
        $r = $dev->get('/api/v1/openapi.json');
        self::assertSame(200, $r->getStatusCode());
        $doc = json_decode($dev->body(), true);
        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame('ShokirjonMK', $doc['info']['contact']['name']);
        self::assertSame('https://github.com/ShokirjonMK', $doc['info']['contact']['url']);
        self::assertSame('ShokirjonMK', $doc['info']['x-author']);
        self::assertSame('bearer', $doc['components']['securitySchemes']['bearerAuth']['scheme']);
        foreach (['/api/v1/auth/token', '/api/v1/auth/refresh', '/api/v1/auth/revoke', '/api/v1/me', '/api/v1/health',
            '/api/v1/users', '/api/v1/users/{id}', '/api/v1/projects', '/api/v1/projects/{id}', '/api/v1/projects/{id}/status', '/api/v1/projects/reports'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path);
        }
        // every $ref resolves inside the document
        $json = (string) json_encode($doc, JSON_UNESCAPED_SLASHES);
        preg_match_all('~"\$ref":"#/components/([a-zA-Z]+)/([A-Za-z0-9_-]+)"~', $json, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $section, $name]) {
            self::assertArrayHasKey($name, $doc['components'][$section] ?? [], "unresolved ref $section/$name");
        }
        $operationIds = [];
        foreach ($doc['paths'] as $ops) {
            foreach ($ops as $op) {
                if (isset($op['operationId'])) {
                    $operationIds[] = $op['operationId'];
                }
                self::assertNotEmpty($op['responses'] ?? []);
            }
        }
        self::assertSame($operationIds, array_unique($operationIds), 'operationIds are unique');
    }

    public function testSwaggerUiPageHasStrictCsp(): void
    {
        [$dev] = \App\Tests\Support\AdminSession::login(['developer']);
        $r = $dev->get('/api/docs');
        self::assertSame(200, $r->getStatusCode());
        $csp = $r->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        $html = $dev->body();
        self::assertStringContainsString('/static/swagger-ui/swagger-ui-bundle.js', $html);
        self::assertStringContainsString('/static/api-docs.js', $html);
        self::assertStringContainsString('Built by', $html);
        self::assertStringContainsString('ShokirjonMK', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, 'no inline scripts');
        self::assertDoesNotMatchRegularExpression('/\sstyle="/i', $html);
        foreach (['/static/swagger-ui/swagger-ui-bundle.js', '/static/swagger-ui/swagger-ui.css', '/static/api-docs.js'] as $asset) {
            self::assertSame(200, $this->http->get($asset)->getStatusCode(), $asset);
        }
    }
}
