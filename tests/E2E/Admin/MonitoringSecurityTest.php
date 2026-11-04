<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Expression\Expression;

/** 17 Monitoring & Observability + 18 Security Center over HTTP. */
final class MonitoringSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testMonitoringShowsLabelledSource(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $r = $web->get('/admin/monitoring');
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('/data-metrics-source="(prometheus|local)"/', $web->body());
        self::assertStringContainsString('data-tile="p95"', $web->body());
        self::assertStringContainsString('hx-trigger="every 30s"', $web->body());
        self::assertStringNotContainsString(' style="', $web->body());
        $r = $web->htmx('GET', '/admin/monitoring/live');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('<div id="monitoring-live"', ltrim($web->body()));
    }

    public function testSecurityCenterPagesAndUnlock(): void
    {
        [$web] = AdminSession::login(['security_admin']);
        $r = $web->get('/admin/security');
        self::assertSame(200, $r->getStatusCode());
        foreach (['data-check="debug"', 'data-check="mfa_privileged"', 'data-check="csp"', 'data-posture-score'] as $needle) {
            self::assertStringContainsString($needle, $web->body());
        }
        self::assertSame(200, $web->get('/admin/security/sessions')->getStatusCode());
        self::assertStringContainsString('this session', $web->body());
        self::assertSame(200, $web->get('/admin/security/asvs')->getStatusCode());
        self::assertStringContainsString('V14', $web->body());

        $victim = Fixtures::user(['user']);
        Fixtures::db()->createCommand()->update('users', ['failed_logins' => 9, 'locked_until' => new Expression("now() + interval '10 minutes'")], ['id' => $victim['id']])->execute();
        $web->get('/admin/security');
        self::assertStringContainsString('data-locked-user="' . $victim['id'] . '"', $web->body());
        $r = $web->post('/admin/security/users/' . $victim['id'] . '/unlock');
        self::assertSame(200, $r->getStatusCode());
        self::assertNull(Fixtures::db()->createCommand('SELECT locked_until FROM users WHERE id = :id', [':id' => $victim['id']])->queryScalar());
    }

    public function testAuditorIsReadOnly(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $victim = Fixtures::user(['user']);
        self::assertSame(200, $web->get('/admin/security')->getStatusCode());
        self::assertStringNotContainsString('/unlock"', $web->body());
        self::assertSame(403, $web->post('/admin/security/users/' . $victim['id'] . '/unlock')->getStatusCode());
        self::assertSame(403, $web->post('/admin/security/users/' . $victim['id'] . '/tokens/revoke')->getStatusCode());
        self::assertSame(403, $web->post('/admin/security/sessions/' . str_repeat('a', 64) . '/revoke')->getStatusCode());
    }
}
