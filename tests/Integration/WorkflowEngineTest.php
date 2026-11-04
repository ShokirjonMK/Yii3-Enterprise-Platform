<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Workflow\ApprovalService;
use App\Platform\Workflow\WorkflowEngine;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/** WorkflowEngine + ApprovalService on the real database (seeded project.lifecycle and ad-hoc workflows). */
final class WorkflowEngineTest extends IntegrationTestCase
{
    private WorkflowEngine $engine;
    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->get(WorkflowEngine::class);
        $this->approvals = $this->get(ApprovalService::class);
    }

    protected function tearDown(): void
    {
        $this->db->createCommand("DELETE FROM workflow_instances WHERE entity_id LIKE 'it-%'")->execute();
        $this->db->createCommand("DELETE FROM workflows WHERE code LIKE 'it.%'")->execute();
        parent::tearDown();
    }

    public function testProjectLifecycleWithFourEyesArchive(): void
    {
        $owner = Fixtures::user(['business_admin'])['id'];
        $approver = Fixtures::user(['business_admin'])['id'];
        $entity = 'it-' . bin2hex(random_bytes(4));

        $i = $this->engine->start('project.lifecycle', 'project', $entity, $owner);
        self::assertSame('draft', $i['state']);
        try {
            $this->engine->start('project.lifecycle', 'project', $entity, $owner);
            self::fail('duplicate instance');
        } catch (ConflictException) {
        }
        self::assertSame(['activate'], array_column($this->engine->availableTransitions($i, $owner), 'name'));

        $r = $this->engine->apply($i['id'], 'activate', $owner, (int) $i['version'], 'go');
        self::assertSame('applied', $r['status']);
        self::assertSame('active', $r['instance']['state']);
        try {
            $this->engine->apply($i['id'], 'hold', $owner, (int) $i['version']);
            self::fail('stale version must conflict');
        } catch (ConflictException $e) {
            self::assertSame('stale_version', $e->errorCode());
        }
        try {
            $this->engine->apply($i['id'], 'archive', $owner);
            self::fail('archive not allowed from active');
        } catch (ConflictException $e) {
            self::assertSame('invalid_transition', $e->errorCode());
        }
        $this->engine->apply($i['id'], 'complete', $owner);

        $pending = $this->engine->apply($i['id'], 'archive', $owner, null, 'please archive');
        self::assertSame('pending_approval', $pending['status']);
        self::assertSame('completed', $pending['instance']['state'], 'state unchanged until approved');
        try {
            $this->engine->apply($i['id'], 'archive', $owner);
            self::fail('second request must conflict');
        } catch (ConflictException) {
        }

        try {
            $this->approvals->decide($pending['approval_id'], $owner, 'approve');
            self::fail('requester cannot approve');
        } catch (AccessDeniedException) {
        }
        self::assertNotContains($pending['approval_id'], array_column($this->approvals->pendingFor($owner), 'id'));
        self::assertContains($pending['approval_id'], array_column($this->approvals->pendingFor($approver), 'id'));

        self::assertSame('approved', $this->approvals->decide($pending['approval_id'], $approver, 'approve', 'ok'));
        self::assertSame('archived', $this->engine->instance($i['id'])['state']);
        $history = array_column($this->engine->history($i['id']), 'transition');
        self::assertSame(['start', 'activate', 'complete', 'archive'], $history);
        try {
            $this->approvals->decide($pending['approval_id'], $approver, 'approve');
            self::fail('closed request');
        } catch (ConflictException) {
        }
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'workflow.transition', 'entity_id' => $entity])->exists());
    }

    public function testQuorumRejectExpiryAndPermissions(): void
    {
        $manager = Fixtures::user(['platform_admin'])['id'];
        $def = json_encode([
            'states' => ['draft', 'review', 'published'],
            'initial' => 'draft',
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'review', 'permission' => 'deploy.approve'],
                'publish' => ['from' => ['review'], 'to' => 'published', 'approval' => ['permission' => 'approvals.decide', 'required_approvals' => 2]],
                'back' => ['from' => ['review'], 'to' => 'draft'],
            ],
        ]);
        try {
            $this->engine->createWorkflow($manager, ['code' => 'it.bad', 'name' => 'x', 'entity_type' => 'doc', 'definition' => '{"states":["a","b"],"initial":"a","transitions":{}}']);
            self::fail('unreachable state');
        } catch (ValidationException $e) {
            self::assertStringContainsString('unreachable', implode(' ', $e->errors()['definition']));
        }
        $code = 'it.' . bin2hex(random_bytes(3));
        $this->engine->createWorkflow($manager, ['code' => $code, 'name' => 'Docs', 'entity_type' => 'doc', 'definition' => $def, 'enabled' => '1']);

        $author = Fixtures::user(['business_admin'])['id']; // has approvals.decide but not deploy.approve
        $a1 = Fixtures::user(['business_admin'])['id'];
        $a2 = Fixtures::user(['business_admin'])['id'];

        $i = $this->engine->start($code, 'doc', 'it-' . bin2hex(random_bytes(4)), $author);
        self::assertSame([], array_column($this->engine->availableTransitions($i, $author), 'name'), 'submit needs deploy.approve');
        try {
            $this->engine->apply($i['id'], 'submit', $author);
            self::fail('permission check');
        } catch (AccessDeniedException) {
        }
        $this->engine->apply($i['id'], 'submit', $manager);
        $req = $this->engine->apply($i['id'], 'publish', $author)['approval_id'];

        self::assertSame('pending', $this->approvals->decide($req, $a1, 'approve'));
        try {
            $this->approvals->decide($req, $a1, 'approve');
            self::fail('each approver once');
        } catch (ConflictException) {
        }
        self::assertSame('review', $this->engine->instance($i['id'])['state']);
        self::assertSame('approved', $this->approvals->decide($req, $a2, 'approve'));
        self::assertSame('published', $this->engine->instance($i['id'])['state'], 'quorum of 2 reached');

        // reject closes
        $i2 = $this->engine->start($code, 'doc', 'it-' . bin2hex(random_bytes(4)), $author);
        $this->engine->apply($i2['id'], 'submit', $manager);
        $req2 = $this->engine->apply($i2['id'], 'publish', $author)['approval_id'];
        try {
            $this->approvals->decide($req2, $a1, 'reject');
            self::fail('reject requires a comment');
        } catch (ValidationException) {
        }
        self::assertSame('rejected', $this->approvals->decide($req2, $a1, 'reject', 'not ready'));
        self::assertSame('review', $this->engine->instance($i2['id'])['state']);

        // expiry
        $req3 = $this->engine->apply($i2['id'], 'publish', $author)['approval_id'];
        $this->db->createCommand("UPDATE approval_requests SET expires_at = now() - interval '1 minute' WHERE id = :id", [':id' => $req3])->execute();
        self::assertNotContains($req3, array_column($this->approvals->pendingFor($a1), 'id'));
        try {
            $this->approvals->decide($req3, $a1, 'approve');
            self::fail('expired');
        } catch (ConflictException $e) {
            self::assertSame('expired', $e->errorCode());
        }
        self::assertSame('expired', (new Query($this->db))->select('status')->from('approval_requests')->where(['id' => $req3])->scalar());

        // a different transition makes pending requests obsolete
        $req4 = $this->engine->apply($i2['id'], 'publish', $author)['approval_id'];
        $this->engine->apply($i2['id'], 'back', $author);
        self::assertSame('cancelled', (new Query($this->db))->select('status')->from('approval_requests')->where(['id' => $req4])->scalar());
        self::assertGreaterThanOrEqual(0, $this->approvals->expireOverdue());
    }
}
