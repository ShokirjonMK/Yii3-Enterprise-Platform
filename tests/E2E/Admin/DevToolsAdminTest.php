<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** 22 · Developer Tools (dev stack: APP_ENV=dev, devtools.gii seeded enabled). */
final class DevToolsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testTabsRenderWithoutSecrets(): void
    {
        [$web] = AdminSession::login(['developer']);
        foreach (['routes', 'jobs', 'consumers', 'commands', 'environment', 'extensions', 'migrations'] as $tab) {
            $r = $web->get('/admin/devtools?tab=' . $tab);
            self::assertSame(200, $r->getStatusCode(), $tab . ': ' . substr($web->body(), 0, 300));
            self::assertStringContainsString('data-tab="' . $tab . '"', $web->body());
        }
        $web->get('/admin/devtools?tab=routes&q=admin.integrations');
        self::assertStringContainsString('integrations.manage', $web->body());
        $web->get('/admin/devtools?tab=jobs');
        self::assertStringContainsString('platform.webhooks.ack', $web->body());
        $web->get('/admin/devtools?tab=environment');
        self::assertStringContainsString('Yii3 Enterprise Platform · built by ShokirjonMK', html_entity_decode($web->body()));
        self::assertStringNotContainsString('APP_SECRET', $web->body());
        self::assertStringNotContainsString('DB_PASSWORD', $web->body());
        $web->get('/admin/devtools?tab=migrations');
        self::assertStringContainsString('M261104040001IntegrationsExtras', $web->body());
    }

    public function testRequiresPermission(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(403, $web->get('/admin/devtools')->getStatusCode());
    }
}
