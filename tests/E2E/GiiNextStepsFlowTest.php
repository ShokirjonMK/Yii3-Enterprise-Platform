<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * Admin → Developer tools → Enterprise Gii → Next steps against the running stack: the page shows the state,
 * a run needs step-up authentication, executes on the queue worker (or inline without one) and the live fragment
 * reports per-step status and the console output until it stops polling.
 */
final class GiiNextStepsFlowTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testRunRbacSyncFromThePanelWithLiveLog(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        $r = $web->get('/admin/devtools/gii/next-steps');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 300));
        foreach (['Apply database migrations', 'Synchronise roles and permissions', 'Create Kafka topics', 'History'] as $text) {
            self::assertStringContainsString($text, $web->body());
        }
        if (str_contains($web->body(), 'A run is in progress')) {
            self::markTestSkipped('another Next steps run is active on this stack');
        }

        AdminSession::reauth($web, $user);
        $web->post('/admin/devtools/gii/next-steps', ['steps' => ['rbac'], 'module' => 'E2E']);
        self::assertMatchesRegularExpression('#^/admin/devtools/gii/runs/[0-9a-f-]{36}$#', $web->url(), substr($web->body(), 0, 500));
        $runUrl = $web->url();

        $body = '';
        for ($i = 0; $i < 90; $i++) {
            $live = $web->get($runUrl . '/live', ['HX-Request' => 'true']);
            self::assertSame(200, $live->getStatusCode());
            $body = $web->body();
            if (!str_contains($body, 'hx-trigger="every 1s"')) {
                break;
            }
            usleep(500_000);
        }
        self::assertStringNotContainsString('hx-trigger="every 1s"', $body, 'polling stops when the run is finished');
        self::assertStringContainsString('$ ./yii rbac:sync', html_entity_decode($body));
        self::assertStringContainsString('SUCCEEDED', $body);
        self::assertStringContainsString('badge--ok', $body);

        $web->get('/admin/devtools/gii/next-steps');
        self::assertStringContainsString($runUrl, $web->body(), 'listed in the history');
        self::assertSame(1, (int) Fixtures::db()->createCommand("SELECT count(*) FROM audit_log WHERE action = 'devtools.next_steps.finished' AND entity_id = :id", [':id' => basename($runUrl)])->queryScalar());
    }

    public function testLowerRoleIsForbidden(): void
    {
        [$web] = AdminSession::login(['user']);
        self::assertSame(403, $web->get('/admin/devtools/gii/next-steps')->getStatusCode());
        $anon = new WebClient();
        $anon->get('/admin/devtools/gii/next-steps');
        self::assertStringStartsWith('/login', $anon->url());
    }
}
