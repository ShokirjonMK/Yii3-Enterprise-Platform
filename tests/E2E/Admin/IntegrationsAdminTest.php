<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * 07 · Integrations admin through nginx: list, create, show, test (SSRF-blocked target), circuit reset, security
 * change behind step-up auth, webhook endpoint with secret shown once, permissions.
 */
final class IntegrationsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM integrations WHERE code LIKE 'e2e-%'")->execute();
        $db->createCommand("DELETE FROM webhook_endpoints WHERE code LIKE 'e2e-%'")->execute();
        Fixtures::cleanup();
    }

    public function testIntegrationLifecycle(): void
    {
        [$web, $user] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/integrations');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 500));
        self::assertStringContainsString('Integrations', $web->body());

        $web->get('/admin/integrations/new');
        $code = 'e2e-' . bin2hex(random_bytes(3));
        $r = $web->post('/admin/integrations', ['code' => $code, 'name' => 'E2E target', 'base_url' => 'http://nginx:8080', 'health_path' => '/health/live', 'auth_type' => 'none', 'headers' => '{"X-Test": "1"}']);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('#^/admin/integrations/[0-9a-f-]{36}$#', $web->url(), $web->body());
        $showUrl = $web->url();
        self::assertStringContainsString($code, $web->body());

        // validation error re-renders with 422
        $web->get('/admin/integrations/new');
        $r = $web->post('/admin/integrations', ['code' => 'BAD CODE', 'name' => '', 'base_url' => 'ftp://x']);
        self::assertSame(422, $r->getStatusCode());

        // test connection: nginx is a private address → blocked by the SSRF guard (status failed)
        $web->get($showUrl);
        $r = $web->htmx('POST', $showUrl . '/test');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('data-test-status="failed"', $web->body());
        self::assertStringContainsString('ssrf_blocked', $web->body());

        // security settings require step-up auth (age the login-time re-auth beyond the 5-minute window)
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $user['id']])->execute();
        $web->get($showUrl);
        $web->post($showUrl . '/security', ['allow_private_network' => '1', 'secret' => '']);
        self::assertStringStartsWith('/auth/confirm', $web->url());
        AdminSession::reauth($web, $user);
        $web->get($showUrl);
        $web->post($showUrl . '/security', ['allow_private_network' => '1', 'secret' => '']);
        self::assertSame($showUrl, $web->url());
        self::assertStringContainsString('allowed', $web->body());

        // now the internal target is reachable
        $r = $web->htmx('POST', $showUrl . '/test');
        self::assertStringContainsString('data-test-status="healthy"', $web->body(), $web->body());

        $web->get('/admin/integrations/logs');
        self::assertStringContainsString($code, $web->body());
        self::assertStringContainsString('ssrf_blocked', $web->body());

        $web->get($showUrl);
        $web->post($showUrl . '/toggle', ['enabled' => '1']);
        self::assertStringContainsString('Integration enabled.', $web->body());
    }

    public function testWebhookSecretShownOnce(): void
    {
        [$web] = AdminSession::login(['platform_admin']);
        $web->get('/admin/integrations/webhooks/new');
        $code = 'e2e-' . bin2hex(random_bytes(3));
        $r = $web->post('/admin/integrations/webhooks', ['code' => $code, 'name' => 'E2E hook', 'tolerance_seconds' => '300', 'enabled' => '1']);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('/data-secret>whsec_[A-Za-z0-9_-]{40,}</', $web->body());
        preg_match('/data-secret>(whsec_[^<]+)</', $web->body(), $m);

        $web->get('/admin/integrations/webhooks');
        self::assertStringContainsString($code, $web->body());
        self::assertStringNotContainsString($m[1], $web->body(), 'secret never shown again');
    }

    public function testAuditorCanViewButNotManage(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/integrations')->getStatusCode());
        self::assertSame(403, $web->get('/admin/integrations/new')->getStatusCode());
        $web->get('/admin/integrations');
        self::assertSame(403, $web->post('/admin/integrations', ['code' => 'e2e-x', 'name' => 'x'])->getStatusCode());
    }
}
