<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Integrations\AlertNotifier;
use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Http\CircuitBreaker;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Push\PushSubscriptions;
use App\Platform\Integrations\Telegram\TelegramBotClient;
use App\Platform\Notifications\Channel\SmsChannel;
use App\Platform\Notifications\Channel\TelegramChannel;
use App\Platform\Notifications\Channel\WebPushChannel;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Security\UrlGuard;
use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use App\Tests\Support\Fixtures;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Yiisoft\Db\Query\Query;

/**
 * Telegram / SMS / Web Push channel adapters and AlertNotifier against mocked providers (Guzzle MockHandler),
 * with real settings (PostgreSQL), Redis and SMTP (Mailpit) for the e-mail fallback.
 */
final class ChannelsProvidersTest extends IntegrationTestCase
{
    private const KEYS = ['notifications.telegram_bot_token', 'notifications.telegram_alert_chat', 'notifications.sms_gateway_url', 'notifications.sms_gateway_token',
        'notifications.sms_sender', 'notifications.vapid_public_key', 'notifications.vapid_private_key', 'app.support_email'];

    private string $admin;
    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Fixtures::user(['platform_admin'])['id'];
        $this->clearSettings();
        foreach (['telegram', 'sms'] as $c) {
            $this->get(CircuitBreaker::class)->reset($c);
        }
    }

    protected function tearDown(): void
    {
        $this->clearSettings();
        parent::tearDown();
    }

    private function clearSettings(): void
    {
        $this->db->createCommand()->delete('settings', ['key' => self::KEYS])->execute();
        $this->get(\App\Platform\Settings\ConfigRevision::class)->bump();
    }

    private function set(string $key, mixed $value): void
    {
        $this->get(SettingsService::class)->set($this->admin, $key, $value);
    }

    private function http(array $responses): HttpClient
    {
        $mock = new MockHandler(array_map(fn($resp) => function (RequestInterface $r) use ($resp) {
            $this->sent[] = $r;
            return $resp;
        }, $responses));
        return $this->get(HttpClient::class)->withHandler($mock)->withGuard(new UrlGuard(static fn(): array => ['149.154.167.220']));
    }

    public function testTelegramChannel(): void
    {
        $settings = $this->get(SettingsService::class);
        $channel = new TelegramChannel($settings, new TelegramBotClient($this->http([])));
        self::assertSame('unconfigured', $channel->health()['status']);
        try {
            $channel->send(['id' => 'n1', 'recipient' => '123', 'subject' => 's', 'body' => 'b']);
            self::fail('unconfigured must be permanent');
        } catch (PermanentJobFailure) {
        }

        $token = '123456:AAE-test-token-xyz';
        $this->set('notifications.telegram_bot_token', $token);
        $channel = new TelegramChannel($settings, new TelegramBotClient($this->http([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"result":{"message_id":777}}'),
            new Response(400, ['Content-Type' => 'application/json'], '{"ok":false,"description":"Bad Request: chat not found"}'),
            new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '60'], '{"ok":false}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"result":{"username":"platform_bot"}}'),
        ])));
        self::assertSame('777', $channel->send(['id' => 'n1', 'recipient' => '-1001234', 'subject' => 'Deploy <done>', 'body' => 'A & B']));
        $req = $this->sent[0];
        self::assertSame('/bot' . $token . '/sendMessage', $req->getUri()->getPath());
        $payload = json_decode((string) $req->getBody(), true);
        self::assertSame('-1001234', $payload['chat_id']);
        self::assertSame("<b>Deploy &lt;done&gt;</b>\n\nA &amp; B", $payload['text']);
        self::assertSame('HTML', $payload['parse_mode']);

        try {
            $channel->send(['id' => 'n2', 'recipient' => '42', 'subject' => '', 'body' => 'x']);
            self::fail('400 must be permanent');
        } catch (PermanentJobFailure $e) {
            self::assertStringContainsString('400', $e->getMessage());
        }
        try {
            $channel->send(['id' => 'n3', 'recipient' => '42', 'subject' => '', 'body' => 'x']);
            self::fail('429 must be retryable');
        } catch (IntegrationException $e) {
            self::assertTrue($e->isRetryable());
        }
        self::assertSame(['status' => 'healthy', 'details' => ['bot' => '@platform_bot']], $channel->health());

        $logs = json_encode((new Query($this->db))->from('integration_logs')->where(['like', 'url', 'api.telegram.org'])->orderBy(['id' => SORT_DESC])->limit(10)->all(), JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($token, $logs, 'bot token masked in logged URL');
        self::assertStringContainsString('/bot***/sendMessage', $logs);
    }

    public function testSmsChannel(): void
    {
        $settings = $this->get(SettingsService::class);
        $guard = new UrlGuard(static fn(): array => ['93.184.216.34']);
        self::assertSame('unconfigured', (new SmsChannel($settings, $this->http([]), $guard))->health()['status']);

        $this->set('notifications.sms_gateway_url', 'https://sms.example.test/v1/messages');
        $this->set('notifications.sms_gateway_token', 'sms-token-777');
        $this->set('notifications.sms_sender', 'PLATFORM');
        $sms = new SmsChannel($settings, $this->http([
            new Response(200, ['Content-Type' => 'application/json'], '{"message_id":"m-1"}'),
            new Response(401, [], '{"error":"bad token"}'),
            new Response(503),
        ]), $guard);
        self::assertSame('m-1', $sms->send(['recipient' => '+998 90 123-45-67', 'body' => 'Code 1234']));
        $req = $this->sent[0];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('Bearer sms-token-777', $req->getHeaderLine('Authorization'));
        self::assertSame(['to' => '+998901234567', 'text' => 'Code 1234', 'from' => 'PLATFORM'], json_decode((string) $req->getBody(), true));

        try {
            $sms->send(['recipient' => '+998901234567', 'body' => 'x']);
            self::fail('401 must be permanent');
        } catch (PermanentJobFailure) {
        }
        try {
            $sms->send(['recipient' => '+998901234567', 'body' => 'x']);
            self::fail('503 must be retryable');
        } catch (IntegrationException $e) {
            self::assertTrue($e->isRetryable());
        }
        $this->expectException(PermanentJobFailure::class);
        $sms->send(['recipient' => 'not-a-phone', 'body' => 'x']);
    }

    public function testWebPushChannelWithSubscriptions(): void
    {
        $settings = $this->get(SettingsService::class);
        $user = Fixtures::user(['user'])['id'];
        $guard = new UrlGuard(static fn(): array => ['142.250.74.10']);
        $subs = new PushSubscriptions($this->db, $guard);
        self::assertSame('unconfigured', (new WebPushChannel($settings, $subs, $guard))->health()['status']);

        $vapid = VAPID::createVapidKeys();
        $this->set('notifications.vapid_public_key', $vapid['publicKey']);
        $this->set('notifications.vapid_private_key', $vapid['privateKey']);
        $this->set('app.support_email', 'ops@example.test');

        // browser-side key pair (P-256) + auth secret, as PushSubscription.toJSON() would deliver them
        $mk = static function (): array {
            $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $d = openssl_pkey_get_details($k)['ec'];
            $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
            return ['p256dh' => rtrim(strtr(base64_encode($pub), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')];
        };
        $ep1 = 'https://fcm.googleapis.com/fcm/send/it-' . bin2hex(random_bytes(6));
        $ep2 = 'https://fcm.googleapis.com/fcm/send/it-' . bin2hex(random_bytes(6));
        $subs->save($user, ['endpoint' => $ep1, 'keys' => $mk()]);
        $subs->save($user, ['endpoint' => $ep2, 'keys' => $mk()]);
        try {
            $subs->save($user, ['endpoint' => 'http://169.254.169.254/latest', 'keys' => $mk()]);
            self::fail('SSRF endpoint must be rejected');
        } catch (\App\Shared\Domain\Exception\ValidationException) {
        }

        $mock = new MockHandler([new Response(201), new Response(410)]);
        $channel = new WebPushChannel($settings, $subs, $guard, $mock);
        self::assertSame('healthy', $channel->health()['status']);
        $id = $channel->send(['id' => 'n1', 'recipient' => $user, 'subject' => 'Hello', 'body' => 'World']);
        self::assertSame('push:1/2', $id);
        self::assertCount(1, $subs->forUser($user), 'expired (410) subscription removed');
        self::assertSame('aes128gcm', $mock->getLastRequest()?->getHeaderLine('Content-Encoding'));
        self::assertStringStartsWith('vapid t=', $mock->getLastRequest()?->getHeaderLine('Authorization'));
    }

    public function testAlertNotifierFallsBackToEmailAndDeduplicates(): void
    {
        $this->set('notifications.telegram_bot_token', '999:alert-token');
        $this->set('notifications.telegram_alert_chat', '-100555');
        $this->set('app.support_email', 'ops@example.test');
        $limiter = $this->get(RedisRateLimiter::class);
        $fp = 'it-' . bin2hex(random_bytes(4));
        $alerts = new AlertNotifier(
            $this->get(SettingsService::class),
            new TelegramBotClient($this->http([new Response(500), new Response(500), new Response(500)])),
            $this->get(TransportInterface::class),
            $limiter,
            $this->get(LoggerInterface::class),
            'platform@example.test',
        );
        $r = $alerts->alert('Circuit opened', 'Integration crm is failing', 'critical', ['integration' => 'crm', 'token' => 'leak'], $fp);
        self::assertTrue($r['delivered'], json_encode($r));
        self::assertSame('email', $r['channel']);
        self::assertStringContainsString('telegram', $r['errors'][0]);
        $again = $alerts->alert('Circuit opened', 'Integration crm is failing', 'critical', [], $fp);
        self::assertTrue($again['suppressed']);
        $limiter->reset('alert:' . $fp);

        $ok = new AlertNotifier(
            $this->get(SettingsService::class),
            new TelegramBotClient($this->http([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"result":{"message_id":1}}')])),
            $this->get(TransportInterface::class),
            $limiter,
            $this->get(LoggerInterface::class),
        );
        self::assertSame('telegram', $ok->alert('Backup failed', 'pg_dump exit 1', 'error', [], $fp . 'b')['channel']);
        $limiter->reset('alert:' . $fp . 'b');
    }
}
