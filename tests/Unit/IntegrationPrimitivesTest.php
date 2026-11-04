<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Integrations\Exception;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Webhook\WebhookSigner;
use App\Platform\Security\UrlGuard;
use App\Platform\Workflow\WorkflowDefinition;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure unit tests: SSRF ranges, webhook signatures, error classification, workflow definition validation. */
final class IntegrationPrimitivesTest extends TestCase
{
    public static function blockedIps(): array
    {
        return [['127.0.0.1'], ['10.1.2.3'], ['172.18.0.5'], ['192.168.1.1'], ['169.254.169.254'], ['169.254.1.1'], ['100.64.0.1'], ['0.0.0.0'],
            ['224.0.0.1'], ['::1'], ['fe80::1'], ['fd00::1'], ['::ffff:127.0.0.1'], ['::ffff:10.0.0.1'], ['fd00:ec2::254']];
    }

    #[DataProvider('blockedIps')]
    public function testPrivateRangesAreBlocked(string $ip): void
    {
        self::assertNotNull(UrlGuard::blockReason($ip), $ip);
    }

    public function testPublicAndAllowPrivate(): void
    {
        self::assertNull(UrlGuard::blockReason('93.184.216.34'));
        self::assertNull(UrlGuard::blockReason('2606:2800:220:1:248:1893:25c8:1946'));
        self::assertNull(UrlGuard::blockReason('10.0.0.5', true), 'allow_private_network');
        self::assertNotNull(UrlGuard::blockReason('169.254.169.254', true), 'metadata is blocked even with allow_private_network');
        self::assertTrue(UrlGuard::inCidr('172.31.255.255', '172.16.0.0/12'));
        self::assertFalse(UrlGuard::inCidr('172.32.0.0', '172.16.0.0/12'));
    }

    public function testGuardWithFakeResolver(): void
    {
        $guard = new UrlGuard(static fn(string $host): array => $host === 'rebind.test' ? ['93.184.216.34', '10.0.0.1'] : ['93.184.216.34']);
        self::assertSame(['93.184.216.34'], $guard->assertAllowed('https://api.example.com/x')['ips']);
        foreach (['ftp://api.example.com', 'file:///etc/passwd', 'https://user:pw@api.example.com', 'https://rebind.test/', 'http://127.0.0.1:8080', 'http://[::1]/'] as $url) {
            try {
                $guard->assertAllowed($url);
                self::fail("$url must be rejected");
            } catch (Exception\SsrfBlockedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWebhookSignatureVerify(): void
    {
        $secret = 'whsec_test';
        $body = '{"id":"evt_1"}';
        $ts = 1767225600;
        $headers = WebhookSigner::headers($secret, $body, $ts);
        self::assertNull(WebhookSigner::verify($secret, (string) $ts, $body, $headers['X-Signature'], 300, $ts + 10));
        self::assertNull(WebhookSigner::verify($secret, (string) $ts, $body, 'v1=bad, ' . $headers['X-Signature'], 300, $ts), 'rotation: any candidate');
        self::assertSame('signature_mismatch', WebhookSigner::verify($secret, (string) $ts, $body . ' ', $headers['X-Signature'], 300, $ts));
        self::assertSame('signature_mismatch', WebhookSigner::verify('other', (string) $ts, $body, $headers['X-Signature'], 300, $ts));
        self::assertSame('timestamp_outside_tolerance', WebhookSigner::verify($secret, (string) $ts, $body, $headers['X-Signature'], 300, $ts + 301));
        self::assertSame('missing_or_invalid_timestamp', WebhookSigner::verify($secret, 'abc', $body, $headers['X-Signature']));
        self::assertSame('missing_signature', WebhookSigner::verify($secret, (string) time(), $body, null));
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9_-]{43}$/', WebhookSigner::generateSecret());
    }

    public static function statuses(): array
    {
        return [
            [401, Exception\UnauthorizedException::class, 'unauthorized', false],
            [403, Exception\ForbiddenException::class, 'forbidden', false],
            [404, Exception\NotFoundException::class, 'not_found', false],
            [408, Exception\RequestTimeoutException::class, 'timeout', true],
            [422, Exception\ClientErrorException::class, 'client_error', false],
            [429, Exception\RateLimitedException::class, 'rate_limited', true],
            [500, Exception\ServerErrorException::class, 'server_error', true],
            [502, Exception\BadGatewayException::class, 'bad_gateway', true],
            [503, Exception\ServiceUnavailableException::class, 'unavailable', true],
            [504, Exception\GatewayTimeoutException::class, 'gateway_timeout', true],
            [599, Exception\ServerErrorException::class, 'server_error', true],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusClassification(int $status, string $class, string $errorClass, bool $retryable): void
    {
        $e = HttpClient::classifyResponse(new Response($status, ['Retry-After' => '7']), 'crm');
        self::assertInstanceOf($class, $e);
        self::assertSame($errorClass, $e->errorClass());
        self::assertSame($retryable, $e->isRetryable());
        self::assertSame($status, $e->statusCode());
        if ($e instanceof Exception\RateLimitedException) {
            self::assertSame(7, $e->retryAfter());
        }
    }

    public function testTransportClassification(): void
    {
        $req = new Request('GET', 'https://x.test');
        self::assertSame('dns', HttpClient::classifyTransport(new ConnectException('Could not resolve host: x.test', $req, null, ['errno' => 6]), 'x')->errorClass());
        self::assertSame('connect', HttpClient::classifyTransport(new ConnectException('Failed to connect: Connection refused', $req, null, ['errno' => 7]), 'x')->errorClass());
        $t = HttpClient::classifyTransport(new ConnectException('Operation timed out after 3000 ms', $req, null, ['errno' => 28]), 'x');
        self::assertInstanceOf(Exception\RequestTimeoutException::class, $t);
        $tls = HttpClient::classifyTransport(new ConnectException('SSL certificate problem: self signed', $req, null, ['errno' => 60]), 'x');
        self::assertSame('tls', $tls->errorClass());
        self::assertFalse($tls->isRetryable(), 'TLS errors are configuration errors');
        self::assertStringNotContainsString('s3cr3t-token', HttpClient::classifyTransport(new ConnectException('cURL error for https://api.test/bots3cr3t-token/send', $req), 'x', ['s3cr3t-token'])->getMessage());
    }

    public function testWorkflowDefinitionValidation(): void
    {
        $ok = WorkflowDefinition::analyse(['states' => ['a', 'b'], 'initial' => 'a', 'transitions' => ['go' => ['from' => ['a'], 'to' => 'b']]]);
        self::assertSame([], $ok['errors']);

        $bad = WorkflowDefinition::analyse([
            'states' => ['draft', 'active', 'orphan', 'draft'],
            'initial' => 'nope',
            'transitions' => ['go' => ['from' => ['draft', 'ghost'], 'to' => 'missing'], 'x' => ['from' => '*', 'to' => 'active', 'approval' => ['required_approvals' => 0]]],
        ]);
        $all = implode("\n", $bad['errors']);
        self::assertStringContainsString('Duplicate state "draft"', $all);
        self::assertStringContainsString('Initial state "nope"', $all);
        self::assertStringContainsString('unknown source state "ghost"', $all);
        self::assertStringContainsString('unknown target state "missing"', $all);
        self::assertStringContainsString('approval.permission is required', $all);
        self::assertStringContainsString('required_approvals must be 1–10', $all);

        $unreachable = WorkflowDefinition::analyse(['states' => ['a', 'b', 'c'], 'initial' => 'a', 'transitions' => ['go' => ['from' => ['a'], 'to' => 'b']]]);
        self::assertSame(['State "c" is unreachable from the initial state "a".'], $unreachable['errors']);
        self::assertSame(['Invalid JSON: Syntax error'], WorkflowDefinition::analyse('{bad')['errors']);

        $def = WorkflowDefinition::parse(['states' => ['a', 'b'], 'initial' => 'a', 'transitions' => ['go' => ['from' => '*', 'to' => 'b', 'approval' => ['permission' => 'approvals.decide', 'required_approvals' => 2]]]]);
        self::assertSame(['go'], $def->transitionsFrom('b'));
        self::assertSame(2, $def->transitions['go']['approval']['required_approvals']);
    }
}
