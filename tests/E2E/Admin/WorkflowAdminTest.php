<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** 09 · Workflow & Approvals through the UI: definition editor + validation, instance transitions, four-eyes approval. */
final class WorkflowAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM workflow_instances WHERE entity_id LIKE 'e2e-%'")->execute();
        $db->createCommand("DELETE FROM workflows WHERE code LIKE 'e2e.%'")->execute();
        Fixtures::cleanup();
    }

    public function testDefinitionInstanceAndApproval(): void
    {
        [$web] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/workflow');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 300));
        self::assertStringContainsString('project.lifecycle', $web->body());

        $web->get('/admin/workflow/new');
        $web->htmx('POST', '/admin/workflow/validate', ['definition' => '{"states":["a","b"],"initial":"a","transitions":{}}']);
        self::assertStringContainsString('data-valid="0"', $web->body());
        self::assertStringContainsString('unreachable', $web->body());

        $web->get('/admin/workflow/new');
        $code = 'e2e.' . bin2hex(random_bytes(3));
        $def = '{"states":["draft","live"],"initial":"draft","transitions":{"publish":{"from":["draft"],"to":"live","approval":{"permission":"approvals.decide","required_approvals":1}}}}';
        $web->post('/admin/workflow', ['code' => $code, 'name' => 'E2E flow', 'entity_type' => 'doc', 'definition' => $def, 'enabled' => '1']);
        self::assertStringContainsString('Workflow created.', $web->body());
        $wfUrl = $web->url();

        $entity = 'e2e-' . bin2hex(random_bytes(3));
        $web->post($wfUrl . '/start', ['entity_id' => $entity]);
        self::assertStringContainsString('data-state="draft"', $web->body());
        $instanceUrl = $web->url();

        $web->post($instanceUrl . '/apply', ['transition' => 'publish', 'version' => '1', 'comment' => 'ready']);
        self::assertStringContainsString('Approval requested', $web->body());
        $approvalId = (string) Fixtures::db()->createCommand("SELECT r.id FROM approval_requests r JOIN workflow_instances i ON i.id = r.instance_id WHERE i.entity_id = :e", [':e' => $entity])->queryScalar();

        // requester sees four-eyes notice and cannot decide
        $web->get('/admin/workflow/approvals/' . $approvalId);
        self::assertStringContainsString('Four-eyes principle', html_entity_decode($web->body()));
        $web->post('/admin/workflow/approvals/' . $approvalId . '/decide', ['decision' => 'approve']);
        self::assertStringContainsString('data-status="pending"', $web->body());

        // second admin approves from the inbox
        [$other] = AdminSession::login(['business_admin']);
        $other->get('/admin/workflow/approvals');
        self::assertStringContainsString($approvalId, $other->body());
        $other->get('/admin/workflow/approvals/' . $approvalId);
        $other->post('/admin/workflow/approvals/' . $approvalId . '/decide', ['decision' => 'approve', 'comment' => 'lgtm']);
        self::assertStringContainsString('data-status="approved"', $other->body());

        $web->get($instanceUrl);
        self::assertStringContainsString('data-state="live"', $web->body());
    }

    public function testViewerCannotManage(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/workflow')->getStatusCode());
        self::assertSame(403, $web->get('/admin/workflow/new')->getStatusCode());
    }
}
