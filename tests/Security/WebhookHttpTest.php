<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Platform\Integrations\Webhook\WebhookService;
use App\Platform\Integrations\Webhook\WebhookSigner;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Inbound webhook over real HTTP (nginx → app): no session/CSRF, HMAC signature mandatory, idempotent.
 */
final class WebhookHttpTest extends TestCase
{
    private string $code;

    protected function setUp(): void
    {
        Fixtures::enableFeatures(['feature.webhooks']);
    }

    protected function tearDown(): void
    {
        Fixtures::restoreFeatures();
        Fixtures::db()->createCommand('DELETE FROM webhook_endpoints WHERE code = :c', [':c' => $this->code])->execute();
        Fixtures::cleanup();
    }

    public function testSignedDeliveryAcceptedReplayIdempotentForgedRejected(): void
    {
        $admin = Fixtures::user(['superadmin']);
        $this->code = 'e2e-' . bin2hex(random_bytes(3));
        $created = TestContainer::service(WebhookService::class)->create($admin['id'], ['code' => $this->code, 'name' => 'E2E hook', 'enabled' => '1', 'tolerance_seconds' => 300]);

        $http = new Client(['base_uri' => getenv('E2E_BASE_URL') ?: 'http://nginx:8080', 'http_errors' => false]);
        $body = '{"id":"evt_' . bin2hex(random_bytes(4)) . '","type":"demo.ping"}';

        $r = $http->post('/webhooks/' . $this->code, ['body' => $body, 'headers' => WebhookSigner::headers($created['secret'], $body) + ['Content-Type' => 'application/json']]);
        self::assertSame(202, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame([], $r->getHeader('Set-Cookie'), 'stateless endpoint sets no cookies');

        $r = $http->post('/webhooks/' . $this->code, ['body' => $body, 'headers' => WebhookSigner::headers($created['secret'], $body) + ['Content-Type' => 'application/json']]);
        self::assertSame(200, $r->getStatusCode(), 'replay of same event id is acknowledged as duplicate');

        $r = $http->post('/webhooks/' . $this->code, ['body' => $body, 'headers' => WebhookSigner::headers('whsec_wrong', $body) + ['Content-Type' => 'application/json']]);
        self::assertSame(401, $r->getStatusCode());

        $old = WebhookSigner::headers($created['secret'], $body, time() - 3600);
        self::assertSame(401, $http->post('/webhooks/' . $this->code, ['body' => $body, 'headers' => $old + ['Content-Type' => 'application/json']])->getStatusCode(), 'stale timestamp rejected');
    }
}
