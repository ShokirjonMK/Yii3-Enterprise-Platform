<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * 08 · Messaging & Notifications: channels + honest health, test send, templates CRUD + preview, deliveries
 * filter + retry, broadcast to a role; Web Push subscription endpoint.
 */
final class NotificationsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM notification_templates WHERE code LIKE 'e2e.%'")->execute();
        Fixtures::cleanup();
    }

    public function testChannelsTemplatesDeliveriesBroadcast(): void
    {
        [$web, $user] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/notifications');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 300));
        self::assertStringContainsString('Telegram Bot', $web->body());

        // honest health: Telegram without token is "unconfigured", enabling is refused
        $web->htmx('POST', '/admin/notifications/channels/telegram/health');
        self::assertStringContainsString('data-health="unconfigured"', $web->body());
        $web->get('/admin/notifications');
        $web->post('/admin/notifications/channels/telegram/toggle', ['enabled' => '1']);
        self::assertStringContainsString('not configured yet', $web->body());

        // test send in-app to self
        $web->post('/admin/notifications/channels/inapp/test');
        self::assertStringContainsString('Test notification delivered', $web->body());

        // templates: create, preview (draft + stored), update
        $web->get('/admin/notifications/templates/new');
        $code = 'e2e.' . bin2hex(random_bytes(3));
        $web->post('/admin/notifications/templates', ['code' => $code, 'channel' => 'inapp', 'locale' => 'en', 'subject' => 'Hi {name}', 'body' => 'Project {project} is {status}']);
        self::assertStringContainsString('Template created.', $web->body());
        $editUrl = $web->url();
        $web->htmx('POST', '/admin/notifications/templates/preview', ['code' => $code, 'channel' => 'inapp', 'locale' => 'en', 'subject' => 'Hi {name}', 'body' => 'Draft {project}', 'sample' => '{"project":"Alpha"}']);
        self::assertStringContainsString('data-preview-source="draft"', $web->body());
        self::assertStringContainsString('Draft Alpha', $web->body());
        $web->get($editUrl);
        $web->htmx('POST', '/admin/notifications/templates/preview', ['code' => $code, 'channel' => 'email', 'locale' => 'ru', 'sample' => '{"project":"Beta","status":"active"}']);
        self::assertStringContainsString('data-preview-source="stored"', $web->body());
        self::assertStringContainsString('Project Beta is active', $web->body(), 'Notifier::render falls back to inapp/en');
        $web->get($editUrl);
        $r = $web->post($editUrl, ['code' => $code, 'channel' => 'inapp', 'locale' => 'en', 'subject' => '', 'body' => '']);
        self::assertSame(422, $r->getStatusCode());

        // broadcast to a role (in-app) → deliveries list shows it
        $target = Fixtures::user(['auditor']);
        $web->get('/admin/notifications/broadcast');
        $web->post('/admin/notifications/broadcast', ['role' => 'auditor', 'subject' => 'Maintenance', 'message' => 'Tonight 22:00', 'channels' => ['inapp']]);
        self::assertStringContainsString('data-broadcast-result', $web->body());
        $count = (int) Fixtures::db()->createCommand("SELECT count(*) FROM notifications WHERE user_id = :u AND category = 'broadcast'", [':u' => $target['id']])->queryScalar();
        self::assertSame(1, $count);
        $web->get('/admin/notifications/deliveries?channel=inapp&template=platform.broadcast');
        self::assertStringContainsString('platform.broadcast', $web->body());

        // retry of a failed delivery
        $id = (string) Fixtures::db()->createCommand("SELECT id FROM notifications WHERE user_id = :u AND category = 'broadcast'", [':u' => $target['id']])->queryScalar();
        Fixtures::db()->createCommand("UPDATE notifications SET status = 'failed', channel = 'email', last_error = 'smtp down' WHERE id = :id", [':id' => $id])->execute();
        $web->get('/admin/notifications/deliveries/' . $id);
        $web->post('/admin/notifications/deliveries/' . $id . '/retry');
        self::assertStringContainsString('Delivery re-queued.', $web->body());
        // the live worker may already have processed it again: assert that a delivery job was enqueued for the row
        $jobId = (string) Fixtures::db()->createCommand('SELECT job_id FROM notifications WHERE id = :id', [':id' => $id])->queryScalar();
        self::assertSame('platform.notifications.deliver', Fixtures::db()->createCommand('SELECT type FROM jobs WHERE id = :j', [':j' => $jobId])->queryScalar());
        self::assertTrue((bool) Fixtures::db()->createCommand("SELECT count(*) FROM audit_log WHERE action = 'notification.retried' AND entity_id = :id", [':id' => $id])->queryScalar());
    }

    public function testBroadcastRequiresSendPermission(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/notifications')->getStatusCode());
        self::assertSame(403, $web->get('/admin/notifications/broadcast')->getStatusCode());
        $web->get('/admin/notifications');
        self::assertSame(403, $web->post('/admin/notifications/channels/sms/toggle', ['enabled' => '1'])->getStatusCode());
    }

    public function testPushSubscriptionEndpoint(): void
    {
        [$web, $user] = AdminSession::login(['user']);
        $web->get('/account');
        $csrf = $web->csrf();
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($k)['ec'];
        $b64 = static fn(string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/e2e-' . bin2hex(random_bytes(6)),
            'keys' => ['p256dh' => $b64("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => $b64(random_bytes(16))]];
        $r = $web->json('POST', '/notifications/push/subscribe', $sub, ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $r->getStatusCode(), $web->body());
        $r = $web->json('POST', '/notifications/push/subscribe', ['endpoint' => 'http://169.254.169.254/x', 'keys' => $sub['keys']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $r->getStatusCode(), 'SSRF endpoint rejected');
        $r = $web->json('POST', '/notifications/push/subscribe', $sub);
        self::assertNotSame(201, $r->getStatusCode(), 'CSRF token required');
        $r = $web->json('POST', '/notifications/push/unsubscribe', ['endpoint' => $sub['endpoint']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(['removed' => 1], json_decode($web->body(), true));
        self::assertSame(200, $web->json('GET', '/notifications/push/key')->getStatusCode());
    }
}
