<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Audit\AuditLogger;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * 20 · Audit & Compliance over HTTP: search, detail, redacted CSV export, integrity check, authorization.
 */
final class AuditComplianceTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testSearchDetailAndRedactedExport(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        $marker = 'e2e-audit-' . bin2hex(random_bytes(4));
        /** @var AuditLogger $audit */
        $audit = TestContainer::service(AuditLogger::class);
        $audit->record(
            'e2e.audit.probe',
            'e2e',
            $marker,
            ['name' => 'old', 'password' => 'Hunter2-plain'],
            ['name' => 'new', 'api_token' => 'tok-secret-123'],
            ['note' => 'Authorization: Bearer abc.def.ghi', 'marker' => $marker],
            category: 'data',
            actorId: $user['id'],
            actorName: $user['username'],
        );

        $r = $web->get('/admin/audit?entity_id=' . $marker);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('e2e.audit.probe', $web->body());
        preg_match('#/admin/audit/(\d+)#', $web->body(), $m);
        self::assertNotEmpty($m, 'detail link present');

        $r = $web->get('/admin/audit/' . $m[1]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Signature valid', $web->body());
        self::assertStringContainsString('changed', $web->body());
        self::assertStringNotContainsString('Hunter2-plain', $web->body());

        // HTMX filter navigation returns only the main region
        $r = $web->htmx('GET', '/admin/audit?q=' . $marker);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));

        $r = $web->get('/admin/audit/export.csv?entity_id=' . $marker);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('text/csv', $r->getHeaderLine('Content-Type'));
        $csv = $web->body();
        self::assertStringContainsString('e2e.audit.probe', $csv);
        self::assertStringNotContainsString('Hunter2-plain', $csv);
        self::assertStringNotContainsString('tok-secret-123', $csv);
        self::assertStringNotContainsString('abc.def.ghi', $csv);
        self::assertStringContainsString('***', $csv);
    }

    public function testIntegrityVerificationAndPartitions(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $r = $web->get('/admin/audit/integrity');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('audit_log_p', $web->body());
        $r = $web->post('/admin/audit/integrity', ['from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d')]);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('/data-verify-result="(passed|failed)"/', $web->body());
    }

    public function testPermissionsAreEnforced(): void
    {
        [$web] = AdminSession::login(['developer']);
        self::assertSame(403, $web->get('/admin/audit')->getStatusCode());
        self::assertSame(403, $web->get('/admin/audit/export.csv')->getStatusCode());

        [$biz] = AdminSession::login(['business_admin']); // audit.view without audit.export
        self::assertSame(200, $biz->get('/admin/audit')->getStatusCode());
        self::assertSame(403, $biz->get('/admin/audit/export.csv')->getStatusCode());
        $biz->get('/admin/audit/integrity');
        self::assertSame(403, $biz->post('/admin/audit/integrity', ['from' => date('Y-m-d')])->getStatusCode());
    }
}
