<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Integrations\Webhook\WebhookAckJob;
use App\Platform\Integrations\Webhook\WebhookService;
use App\Platform\Integrations\Webhook\WebhookSigner;
use App\Platform\Queue\JobContext;
use App\Shared\Domain\Exception\NotFoundException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/**
 * Inbound webhook pipeline at service level (the HTTP route lives in config/routes/webhooks.php):
 * signature + timestamp tolerance, idempotency, job enqueue (202), invalid signature → 401 + security audit.
 */
final class WebhookReceiveTest extends IntegrationTestCase
{
    private WebhookService $hooks;
    private string $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooks = $this->get(WebhookService::class);
        $this->actor = Fixtures::user(['platform_admin'])['id'];
    }

    protected function tearDown(): void
    {
        $this->db->createCommand("DELETE FROM webhook_endpoints WHERE code LIKE 'it-%'")->execute();
        parent::tearDown();
    }

    private function signed(string $secret, string $body, ?int $ts = null, array $extra = []): array
    {
        $h = WebhookSigner::headers($secret, $body, $ts ?? time());
        return array_change_key_case($h + $extra, CASE_LOWER);
    }

    public function testValidDeliveryIsAcceptedOnceAndEnqueued(): void
    {
        $code = 'it-' . bin2hex(random_bytes(3));
        $created = $this->hooks->create($this->actor, ['code' => $code, 'name' => 'IT hook', 'enabled' => '1', 'tolerance_seconds' => 300]);
        self::assertStringStartsWith('whsec_', $created['secret']);
        $stored = $this->hooks->find($created['id']);
        self::assertStringNotContainsString($created['secret'], (string) $stored['secret_enc'], 'secret stored encrypted');

        $body = '{"id":"evt_' . bin2hex(random_bytes(4)) . '","type":"order.paid","card":"4111111111111111"}';
        $r = $this->hooks->receive($code, $this->signed($created['secret'], $body), $body, '203.0.113.9');
        self::assertSame(202, $r['status']);
        self::assertTrue($r['body']['queued']);
        $event = (new Query($this->db))->from('webhook_events')->where(['id' => $r['body']['event_id']])->one();
        self::assertNotNull($event['job_id']);
        $job = (new Query($this->db))->from('jobs')->where(['id' => $event['job_id']])->one();
        self::assertSame('platform.webhooks.ack', $job['type']);

        // replay of the same event id → 200 duplicate, no second event/job
        $r2 = $this->hooks->receive($code, $this->signed($created['secret'], $body), $body);
        self::assertSame(200, $r2['status']);
        self::assertSame('duplicate', $r2['body']['status']);
        self::assertSame(1, (int) (new Query($this->db))->from('webhook_events')->where(['endpoint_id' => $created['id']])->count());

        // the default job acknowledges the event
        $this->get(WebhookAckJob::class)->handle(json_decode((string) $job['payload'], true), new JobContext((string) $job['id'], 'platform.webhooks.ack', 1, null, static fn() => false, static fn(int $p) => null));
        self::assertNotNull((new Query($this->db))->select('processed_at')->from('webhook_events')->where(['id' => $event['id']])->scalar());
    }

    public function testInvalidSignatureAndStaleTimestampAreRejectedAndAudited(): void
    {
        $code = 'it-' . bin2hex(random_bytes(3));
        $created = $this->hooks->create($this->actor, ['code' => $code, 'name' => 'IT hook', 'enabled' => '1']);
        $body = '{"id":"evt_x"}';

        $r = $this->hooks->receive($code, $this->signed('wrong-secret', $body), $body, '198.51.100.7');
        self::assertSame(401, $r['status']);
        $r = $this->hooks->receive($code, $this->signed($created['secret'], $body, time() - 3600), $body);
        self::assertSame(401, $r['status'], 'replay outside tolerance');
        $r = $this->hooks->receive($code, ['x-timestamp' => (string) time()], $body);
        self::assertSame(401, $r['status'], 'missing signature');
        self::assertSame(0, (int) (new Query($this->db))->from('webhook_events')->where(['endpoint_id' => $created['id']])->count(), 'invalid deliveries are not stored');

        $audit = (new Query($this->db))->from('audit_log')->where(['action' => 'webhook.signature_invalid'])->andWhere(['>', 'occurred_at', date('Y-m-d H:i:sP', time() - 60)])->all();
        $reasons = array_map(static fn($a) => json_decode((string) $a['metadata'], true)['reason'] ?? null, $audit);
        self::assertContains('signature_mismatch', $reasons);
        self::assertContains('timestamp_outside_tolerance', $reasons);
        self::assertContains('missing_signature', $reasons);
    }

    public function testRotationDisableAndUnknownEndpoint(): void
    {
        $code = 'it-' . bin2hex(random_bytes(3));
        $created = $this->hooks->create($this->actor, ['code' => $code, 'name' => 'IT hook', 'enabled' => '1', 'id_header' => 'X-Event']);
        $new = $this->hooks->rotateSecret($this->actor, $created['id']);
        self::assertNotSame($created['secret'], $new);
        $body = 'plain=text';
        self::assertSame(401, $this->hooks->receive($code, $this->signed($created['secret'], $body), $body)['status'], 'old secret no longer valid');
        $ok = $this->hooks->receive($code, $this->signed($new, $body, null, ['X-Event' => 'ext-1']), $body);
        self::assertSame(202, $ok['status']);
        self::assertSame('ext-1', $ok['body']['external_id']);

        $this->hooks->setEnabled($this->actor, $created['id'], false);
        $this->expectException(NotFoundException::class);
        $this->hooks->receive($code, $this->signed($new, $body), $body);
    }
}
