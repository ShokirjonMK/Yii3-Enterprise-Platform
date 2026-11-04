<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Platform\Integrations\Exception\SsrfBlockedException;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use App\Platform\Security\UrlGuard;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard with REAL DNS resolution inside the stack: the internal nginx (Docker network), loopback,
 * metadata and encoded variants must be refused; with allow_private_network the real internal target answers.
 */
final class SsrfTest extends TestCase
{
    public static function blocked(): array
    {
        return [
            'docker service name' => ['http://nginx:8080/health/live'],
            'redis by name' => ['http://redis:6379/'],
            'localhost' => ['http://localhost/'],
            'loopback' => ['http://127.0.0.1:8080/'],
            'loopback v6' => ['http://[::1]/'],
            'decimal loopback' => ['http://2130706433/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'mapped v6' => ['http://[::ffff:127.0.0.1]/'],
            'private 10/8' => ['https://10.0.0.1/'],
            'zero' => ['http://0.0.0.0/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://nginx:8080/_x'],
            'credentials' => ['http://admin:pw@example.com/'],
        ];
    }

    #[DataProvider('blocked')]
    public function testGuardBlocks(string $url): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new UrlGuard())->assertAllowed($url);
    }

    public function testMetadataBlockedEvenWhenPrivateAllowed(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new UrlGuard())->assertAllowed('http://169.254.169.254/latest/meta-data/', true);
    }

    public function testHttpClientRefusesInternalTargetAndNothingIsSent(): void
    {
        /** @var HttpClient $http */
        $http = TestContainer::service(HttpClient::class);
        $cfg = new IntegrationConfig(code: 'ssrf-test', baseUrl: 'http://nginx:8080', maxRetries: 0);
        try {
            $http->send($cfg, 'GET', '/health/live');
            self::fail('internal target must be blocked');
        } catch (SsrfBlockedException $e) {
            self::assertStringContainsString('not allowed', $e->getMessage());
        }
        // explicitly allowed private network → the real internal service answers through the full stack
        $allowed = new IntegrationConfig(code: 'ssrf-test', baseUrl: 'http://nginx:8080', maxRetries: 0, allowPrivateNetwork: true);
        self::assertSame(200, $http->send($allowed, 'GET', '/health/live')->getStatusCode());
    }

    public function testRedirectsAreNotFollowed(): void
    {
        /** @var HttpClient $http */
        $http = TestContainer::service(HttpClient::class);
        // /admin redirects anonymous users to /login: the client must return the 3xx, not follow it
        $cfg = new IntegrationConfig(code: 'ssrf-test', baseUrl: 'http://nginx:8080', maxRetries: 0, allowPrivateNetwork: true);
        $r = $http->send($cfg, 'GET', '/admin');
        self::assertGreaterThanOrEqual(300, $r->getStatusCode());
        self::assertLessThan(400, $r->getStatusCode());
    }
}
