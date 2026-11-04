<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Settings\ConfigRevision;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Optional features can be switched off/on (404 + hidden menu), user activity and application logs are
 * recorded and visible in the admin panel, notification channel credentials are managed in section 08.
 *
 * Author: ShokirjonMK
 */
final class FeaturesActivityLogsTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function setFlag(string $code, bool $on): void
    {
        Fixtures::db()->createCommand()->update('feature_flags', ['enabled' => $on, 'status' => $on ? 'enabled' : 'disabled'], ['code' => $code])->execute();
        TestContainer::service(ConfigRevision::class)->bump();
        sleep(3); // per-process config cache (APCu) is at most 2 s old
    }

    public function testSwitchingAnOptionalSectionOffAndOn(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $was = (bool) Fixtures::db()->createCommand("SELECT enabled FROM feature_flags WHERE code = 'feature.incidents'")->queryScalar();
        try {
            $this->setFlag('feature.incidents', false);
            self::assertSame(404, $web->get('/admin/incidents')->getStatusCode());
            $web->get('/admin');
            self::assertStringNotContainsString('<span>Incident Management</span>', $web->body(), 'menu entry hidden');

            $this->setFlag('feature.incidents', true);
            self::assertSame(200, $web->get('/admin/incidents')->getStatusCode());
            $web->get('/admin');
            self::assertStringContainsString('<span>Incident Management</span>', $web->body());
        } finally {
            $this->setFlag('feature.incidents', $was);
        }
    }

    public function testUserActivityIsRecordedAndShown(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        $web->get('/admin/users');
        $web->get('/admin/rbac');
        $rows = [];
        for ($i = 0; $i < 10 && count($rows) < 2; $i++) {
            usleep(300_000); // written after the response (fastcgi_finish_request)
            $rows = (new Query(Fixtures::db()))->from('user_activity')->where(['user_id' => $user['id']])->andWhere(['in', 'path', ['/admin/users', '/admin/rbac']])->all();
        }
        self::assertCount(2, $rows);
        self::assertSame('GET', $rows[0]['method']);
        self::assertSame(200, (int) $rows[0]['status']);
        self::assertSame($user['username'], $rows[0]['username']);

        $r = $web->get('/admin/audit/activity?user=' . $user['id']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('/admin/rbac', $web->body());
    }

    public function testApplicationLogsViewer(): void
    {
        $db = Fixtures::db();
        $marker = 'smoke-log-' . bin2hex(random_bytes(4));
        $db->createCommand()->insert('app_logs', ['level' => 'error', 'message' => "Test error $marker", 'channel' => 'web', 'correlation_id' => $marker])->execute();
        [$web] = AdminSession::login(['devops_sre']);
        $web->get('/admin/monitoring/logs?q=' . $marker);
        self::assertStringContainsString($marker, $web->body());
        $id = (int) $db->createCommand('SELECT id FROM app_logs WHERE correlation_id = :c', [':c' => $marker])->queryScalar();
        self::assertSame(200, $web->get('/admin/monitoring/logs/' . $id)->getStatusCode());
    }

    public function testTelegramChannelCredentialsManagedInNotificationsSection(): void
    {
        [$web, $user] = AdminSession::login(['platform_admin']);
        $web->get('/admin/notifications');
        self::assertStringContainsString('/admin/notifications/channels/telegram/settings', $web->body());

        $web->get('/admin/notifications/channels/telegram/settings');
        self::assertStringContainsString('notifications_telegram_bot_token', $web->body());

        AdminSession::reauth($web, $user);
        $web->get('/admin/notifications/channels/telegram/settings');
        $token = '123456:TEST-' . bin2hex(random_bytes(6));
        $web->post('/admin/notifications/channels/telegram/settings', [
            'notifications_telegram_bot_token' => $token,
            'notifications_telegram_alert_chat' => '-1001234567890',
        ]);
        self::assertStringNotContainsString($token, $web->body(), 'secret is never echoed back');
        self::assertStringContainsString('-1001234567890', $web->body());

        $raw = (string) Fixtures::db()->createCommand("SELECT value FROM settings WHERE key = 'notifications.telegram_bot_token' AND scope = 'global'")->queryScalar();
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString($token, $raw, 'stored encrypted');

        // clean up the test credentials
        $web->get('/admin/notifications/channels/telegram/settings');
        $web->post('/admin/notifications/channels/telegram/settings', ['clear' => ['notifications.telegram_bot_token'], 'notifications_telegram_alert_chat' => '']);
    }
}
