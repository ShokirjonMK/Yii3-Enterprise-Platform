<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * 15 Infrastructure + 16 CI/CD & Deployments over HTTP (external systems are not configured in dev → honest "Not configured").
 */
final class DeploymentsInfrastructureTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::enableFeatures(['feature.infrastructure', 'feature.deployments']);
        Fixtures::resetLoginThrottle();
        Fixtures::db()->createCommand("UPDATE deployments SET status = 'failed', error = 'e2e cleanup' WHERE environment = 'staging' AND status IN ('requested','approved','running')")->execute();
    }

    protected function tearDown(): void
    {
        Fixtures::restoreFeatures();
        Fixtures::cleanup();
    }

    public function testInfrastructurePages(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $r = $web->get('/admin/infrastructure');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Worker processes', $web->body());
        self::assertStringContainsString('OPcache', $web->body());
        self::assertStringContainsString(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $web->body());
        $r = $web->htmx('GET', '/admin/infrastructure/kubernetes');
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('/data-k8s="not-configured"|Deployments/', $web->body());
    }

    public function testAuditorCanViewButNotScale(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/infrastructure')->getStatusCode());
        self::assertSame(403, $web->post('/admin/infrastructure/kubernetes/scale', ['deployment' => 'app', 'replicas' => 3, 'reason' => 'x'])->getStatusCode());
        self::assertSame(200, $web->get('/admin/deployments')->getStatusCode());
        self::assertSame(403, $web->post('/admin/deployments', ['environment' => 'staging', 'version' => 'v1.0.0'])->getStatusCode());
    }

    public function testFourEyesOverHttp(): void
    {
        [$a, $ua] = AdminSession::login(['platform_admin']);
        $version = 'v3.' . random_int(100, 999) . '.0';
        $a->get('/admin/deployments');
        self::assertStringContainsString('Recent GitLab pipelines', $a->body());
        $r = $a->post('/admin/deployments', ['environment' => 'staging', 'version' => $version, 'strategy' => 'rolling', 'notes' => 'e2e']);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('#/admin/deployments/[0-9a-f-]{36}$#', $a->url());
        $url = $a->url();
        self::assertStringContainsString('data-four-eyes="own"', $a->body());

        // the requester cannot approve, even with a fresh step-up
        AdminSession::reauth($a, $ua);
        $a->get($url);
        self::assertSame(403, $a->post($url . '/approve', [])->getStatusCode());

        // invalid input is rejected with 422
        $a->get('/admin/deployments');
        self::assertSame(422, $a->post('/admin/deployments', ['environment' => 'staging', 'version' => 'bad version!'])->getStatusCode());

        [$b] = AdminSession::login(['platform_admin']);
        $b->get($url);
        $r = $b->post($url . '/approve', ['comment' => 'LGTM']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('approved', $b->body());
        self::assertMatchesRegularExpression('/GitLab is not configured|Pipeline #\d+ started|GitLab/', $b->body());
    }
}
