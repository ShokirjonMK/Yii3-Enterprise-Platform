<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Integrations\Exception;
use App\Platform\Integrations\Http\CircuitBreaker;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use App\Platform\Security\UrlGuard;
use App\Shared\Infrastructure\Context\RequestContext;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Predis\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Yiisoft\Db\Query\Query;

/**
 * Platform HttpClient with a Guzzle MockHandler as transport and REAL Redis (circuit breaker, rate limiter)
 * and PostgreSQL (integration_logs).
 */
final class IntegrationsHttpClientTest extends IntegrationTestCase
{
    private string $code;
    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->code = 'it-' . bin2hex(random_bytes(4));
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        $this->get(CircuitBreaker::class)->reset($this->code);
        parent::tearDown();
    }

    private function client(array $queue): HttpClient
    {
        $mock = new MockHandler(array_map(fn($item) => function (RequestInterface $r) use ($item) {
            $this->sent[] = $r;
            if ($item instanceof \Throwable) {
                throw $item;
            }
            return $item;
        }, $queue));
        return $this->get(HttpClient::class)->withHandler($mock)->withGuard(new UrlGuard(static fn(): array => ['93.184.216.34']));
    }

    private function cfg(array $over = []): IntegrationConfig
    {
        return new IntegrationConfig(...$over + ['code' => $this->code, 'baseUrl' => 'https://api.example.test', 'maxRetries' => 3, 'backoffMs' => 1, 'circuitThreshold' => 50]);
    }

    /** @return list<array<string,mixed>> */
    private function logs(): array
    {
        return (new Query($this->db))->from('integration_logs')->where(['correlation_id' => $this->get(RequestContext::class)->correlationId()])
            ->andWhere(['like', 'url', 'api.example.test'])->orderBy(['id' => SORT_ASC])->all();
    }

    public function testRetriesIdempotentRequestWithBackoffAndPropagatesTracing(): void
    {
        $this->get(RequestContext::class)->setCorrelationId('corr-' . bin2hex(random_bytes(8)));
        $http = $this->client([new Response(503), new Response(502), new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"password":"p@ss"}')]);
        $r = $http->send($this->cfg(['authType' => 'bearer', 'secret' => 'tok-ABCDEF-123']), 'GET', '/v1/items', ['query' => ['token' => 'q-secret']]);
        self::assertSame(200, $r->getStatusCode());
        self::assertCount(3, $this->sent, '2 retries');
        $req = $this->sent[0];
        self::assertSame($this->get(RequestContext::class)->correlationId(), $req->getHeaderLine('X-Request-Id'));
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $req->getHeaderLine('traceparent'));
        self::assertSame('Bearer tok-ABCDEF-123', $req->getHeaderLine('Authorization'));

        $logs = $this->logs();
        self::assertSame([1, 2, 3], array_map('intval', array_column($logs, 'attempt')));
        self::assertSame(['unavailable', 'bad_gateway', null], array_column($logs, 'error_class'));
        $all = json_encode($logs);
        self::assertStringNotContainsString('tok-ABCDEF-123', $all, 'Authorization redacted');
        self::assertStringNotContainsString('q-secret', $all, 'query token redacted');
        self::assertStringNotContainsString('p@ss', $all, 'response body redacted');
        self::assertSame('{"ok":true,"password":"***"}', $logs[2]['response_sample']);
    }

    public function testPostIsNotRetriedUnlessAllowed(): void
    {
        $http = $this->client([new Response(503), new Response(200)]);
        try {
            $http->send($this->cfg(), 'POST', '/orders', ['json' => ['a' => 1]]);
            self::fail('expected exception');
        } catch (Exception\ServiceUnavailableException $e) {
            self::assertSame(503, $e->statusCode());
        }
        self::assertCount(1, $this->sent);

        $this->sent = [];
        $http = $this->client([new Response(503), new Response(201)]);
        self::assertSame(201, $http->send($this->cfg(), 'POST', '/orders', ['json' => ['a' => 1], 'retry' => true])->getStatusCode());
        self::assertCount(2, $this->sent);
    }

    public function testTypedErrorsAndRetryAfter(): void
    {
        foreach ([401 => Exception\UnauthorizedException::class, 403 => Exception\ForbiddenException::class, 404 => Exception\NotFoundException::class,
            408 => Exception\RequestTimeoutException::class, 500 => Exception\ServerErrorException::class, 502 => Exception\BadGatewayException::class,
            503 => Exception\ServiceUnavailableException::class, 504 => Exception\GatewayTimeoutException::class] as $status => $class) {
            try {
                $this->client([new Response($status, [], 'err')])->send($this->cfg(['maxRetries' => 0]), 'GET', '/x');
                self::fail("$status must throw");
            } catch (Exception\IntegrationException $e) {
                self::assertInstanceOf($class, $e, (string) $status);
            }
        }
        $this->sent = [];
        try {
            $this->client([new Response(429, ['Retry-After' => '42']), new Response(200)])->send($this->cfg(['maxRetries' => 3]), 'GET', '/x');
            self::fail('429 must throw');
        } catch (Exception\RateLimitedException $e) {
            self::assertSame(42, $e->retryAfter());
        }
        self::assertCount(1, $this->sent, 'Retry-After > 30 s is not waited for (no retry)');

        $this->sent = [];
        self::assertSame(200, $this->client([new Response(429, ['Retry-After' => '0']), new Response(200)])->send($this->cfg(['maxRetries' => 3]), 'GET', '/x')->getStatusCode());
        self::assertCount(2, $this->sent, 'short Retry-After is honoured and retried');

        try {
            $this->client([new ConnectException('Could not resolve host: api.example.test', new \GuzzleHttp\Psr7\Request('GET', '/'), null, ['errno' => 6])])
                ->send($this->cfg(['maxRetries' => 0]), 'GET', '/x');
            self::fail('network error must throw');
        } catch (Exception\NetworkException $e) {
            self::assertSame('dns', $e->errorClass());
        }
    }

    public function testCircuitBreakerClosedOpenHalfOpenClosed(): void
    {
        $cfg = $this->cfg(['maxRetries' => 0, 'circuitThreshold' => 2, 'circuitCooldownS' => 30]);
        $http = $this->client([new Response(500), new Response(500), new Response(200), new Response(500), new Response(200)]);
        foreach ([1, 2] as $_) {
            try {
                $http->send($cfg, 'GET', '/x');
            } catch (Exception\ServerErrorException) {
            }
        }
        $breaker = $this->get(CircuitBreaker::class);
        self::assertSame('open', $breaker->state($this->code, 30)['state']);
        try {
            $http->send($cfg, 'GET', '/x');
            self::fail('open circuit must fail fast');
        } catch (Exception\CircuitOpenException $e) {
            self::assertGreaterThan(0, $e->retryAfter());
        }
        self::assertCount(2, $this->sent, 'no request while open');

        // cooldown elapsed → half-open: one probe; failure re-opens
        $redis = $this->get(ClientInterface::class);
        $redis->set('integration:cb:' . $this->code . ':open', (string) (time() - 31));
        self::assertSame('half_open', $breaker->state($this->code, 30)['state']);
        self::assertSame(200, $http->send($cfg, 'GET', '/x')->getStatusCode(), 'half-open probe goes through');
        self::assertSame('closed', $breaker->state($this->code, 30)['state'], 'successful probe closes the circuit');

        // failing probe re-opens
        $breaker->recordFailure($this->code, 2, 30);
        $breaker->recordFailure($this->code, 2, 30);
        $redis->set('integration:cb:' . $this->code . ':open', (string) (time() - 31));
        try {
            $http->send($cfg, 'GET', '/x');
        } catch (Exception\ServerErrorException) {
        }
        self::assertSame('open', $breaker->state($this->code, 30)['state']);

        $breaker->reset($this->code);
        self::assertSame(200, $http->send($cfg, 'GET', '/x')->getStatusCode());
    }

    public function testLocalRateLimitAndSsrfAndNotConfigured(): void
    {
        $cfg = $this->cfg(['maxRetries' => 0, 'rateLimitPerMinute' => 2]);
        $http = $this->client([new Response(200), new Response(200), new Response(200)]);
        $http->send($cfg, 'GET', '/a');
        $http->send($cfg, 'GET', '/b');
        try {
            $http->send($cfg, 'GET', '/c');
            self::fail('third call must be rate limited');
        } catch (Exception\RateLimitedException $e) {
            self::assertSame('rate_limited_local', $e->errorClass());
        }
        self::assertCount(2, $this->sent);
        $this->get(\App\Shared\Infrastructure\RateLimit\RedisRateLimiter::class)->reset('integration:' . $this->code);

        $private = $this->get(HttpClient::class)->withHandler(new MockHandler([new Response(200)]))->withGuard(new UrlGuard(static fn(): array => ['10.0.0.8']));
        try {
            $private->send($this->cfg(), 'GET', '/x');
            self::fail('private destination must be blocked');
        } catch (Exception\SsrfBlockedException) {
            self::assertTrue(true);
        }
        self::assertSame(200, $private->send($this->cfg(['allowPrivateNetwork' => true]), 'GET', '/x')->getStatusCode());

        $this->expectException(Exception\NotConfiguredException::class);
        $this->client([])->send($this->cfg(['enabled' => false]), 'GET', '/x');
    }

    public function testBodySamplesAreBoundedAndHmacSigned(): void
    {
        $big = str_repeat('x', 10000);
        $http = $this->client([new Response(200, ['Content-Type' => 'text/plain'], $big)]);
        $this->get(RequestContext::class)->setCorrelationId('corr-' . bin2hex(random_bytes(8)));
        $http->send($this->cfg(['authType' => 'hmac', 'secret' => 'hmac-secret-1']), 'POST', '/hook', ['json' => ['a' => 1]]);
        $req = $this->sent[0];
        $ts = $req->getHeaderLine('X-Timestamp');
        self::assertSame('sha256=' . hash_hmac('sha256', $ts . '.{"a":1}', 'hmac-secret-1'), $req->getHeaderLine('X-Signature'));
        $log = $this->logs()[0];
        self::assertLessThanOrEqual(2048, strlen((string) $log['response_sample']));
        self::assertSame('***', json_decode((string) $log['request_headers'], true)['X-Signature']);
    }
}
