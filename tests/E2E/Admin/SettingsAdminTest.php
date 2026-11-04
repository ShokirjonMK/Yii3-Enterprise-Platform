<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * 21 · System Settings: grouped forms with value source, batch save of regular keys, critical/secret keys only via
 * the step-up route, history + rollback, feature flag page (rollout, override).
 */
final class SettingsAdminTest extends TestCase
{
    private static ?string $original = null;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM settings WHERE key IN ('retention.jobs_days', 'notifications.sms_gateway_token') AND scope = 'global'")->execute();
        $db->createCommand("UPDATE config_revision SET revision = revision + 1")->execute();
        Fixtures::cleanup();
    }

    public function testGroupedSettingsSaveHistoryRollback(): void
    {
        [$web, $user] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/settings?group=Retention');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 300));
        self::assertStringContainsString('data-setting="retention.jobs_days"', $web->body());
        self::assertStringContainsString('data-source="default"', $web->body());

        $web->post('/admin/settings', ['group' => 'Retention', 'scope' => 'global', 'present' => ['retention.jobs_days' => '1'], 'settings' => ['retention.jobs_days' => '21'], 'comment' => 'e2e']);
        self::assertStringContainsString('1 setting(s) saved.', $web->body());
        $web->post('/admin/settings', ['group' => 'Retention', 'scope' => 'global', 'present' => ['retention.jobs_days' => '1'], 'settings' => ['retention.jobs_days' => '22']]);

        // invalid value → 422 with field error
        $r = $web->post('/admin/settings', ['group' => 'Retention', 'scope' => 'global', 'present' => ['retention.jobs_days' => '1'], 'settings' => ['retention.jobs_days' => '9999']]);
        self::assertSame(422, $r->getStatusCode());

        // critical keys are refused by the batch form
        $r = $web->post('/admin/settings', ['group' => 'Retention', 'scope' => 'global', 'present' => ['retention.audit_months' => '1'], 'settings' => ['retention.audit_months' => '100']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('re-authentication required', $web->body());

        $web->get('/admin/settings/key/retention.jobs_days/history');
        self::assertStringContainsString('e2e', $web->body());
        preg_match_all('/name="version" value="(\d+)"/', $web->body(), $m);
        self::assertNotEmpty($m[1], 'older versions offer rollback');
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $user['id']])->execute();
        $web->post('/admin/settings/key/retention.jobs_days/rollback', ['version' => $m[1][0], 'scope' => 'global']);
        self::assertStringStartsWith('/auth/confirm', $web->url(), 'rollback requires step-up auth');
        AdminSession::reauth($web, $user);
        $web->get('/admin/settings/key/retention.jobs_days/history');
        $web->post('/admin/settings/key/retention.jobs_days/rollback', ['version' => $m[1][0], 'scope' => 'global']);
        self::assertStringContainsString('Rolled back to version', $web->body());
        self::assertSame('21', (string) json_decode((string) Fixtures::db()->createCommand("SELECT value FROM settings WHERE key = 'retention.jobs_days' AND scope = 'global'")->queryScalar()));

        // secret: write-only, value never rendered
        $web->get('/admin/settings?group=Notifications');
        $web->post('/admin/settings/secure', ['key' => 'notifications.sms_gateway_token', 'value' => 'tok-e2e-SECRET-123', 'scope' => 'global']);
        self::assertStringContainsString('Secret updated.', $web->body());
        self::assertStringNotContainsString('tok-e2e-SECRET-123', $web->body());
        $stored = (string) Fixtures::db()->createCommand("SELECT value FROM settings WHERE key = 'notifications.sms_gateway_token'")->queryScalar();
        self::assertStringNotContainsString('tok-e2e', $stored, 'stored encrypted');
    }

    public function testFeatureFlagPages(): void
    {
        [$web] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/settings/flags');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('data-flag="security.audit"', $web->body());
        $web->get('/admin/settings/flags/notifications.sms');
        self::assertStringContainsString('Dependency problem', $web->body(), 'unconfigured channel dependency is shown');
        $web->get('/admin/settings/flags/security.audit');
        self::assertStringContainsString('critical control', $web->body());
        $r = $web->post('/admin/settings/flags/ui.partial_navigation/configure', ['rollout_percentage' => '150']);
        self::assertSame(422, $r->getStatusCode());
    }

    public function testAuditorIsReadOnly(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/settings')->getStatusCode());
        self::assertStringNotContainsString('Save changes', $web->body());
        self::assertSame(403, $web->post('/admin/settings', ['group' => 'Retention'])->getStatusCode());
    }
}
