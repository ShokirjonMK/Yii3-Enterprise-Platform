<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Deployments\DeploymentService;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Infrastructure\InfrastructureService;
use App\Platform\Infrastructure\OpsClients;
use App\Platform\Queue\JobQueue;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Tests\Support\Fixtures;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Yiisoft\Db\Query\Query;

/**
 * Deployment registry with four-eyes approval and GitLab pipeline trigger/poll (GitLab mocked),
 * plus Kubernetes scaling through InfrastructureService (Kubernetes API mocked).
 */
final class OpsDeploymentsTest extends IntegrationTestCase
{
    private array $history = [];
    private const ENV = ['GITLAB_URL' => 'https://gitlab.test', 'GITLAB_PROJECT_ID' => '42', 'GITLAB_TOKEN' => 'glpat-test', 'K8S_API_URL' => 'https://k8s.test:6443', 'K8S_TOKEN' => 'k8s-test', 'K8S_NAMESPACE' => 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->createCommand("UPDATE deployments SET status = 'failed' WHERE environment = 'dev' AND status IN ('requested','approved','running')")->execute();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $k) {
            putenv($k);
        }
        parent::tearDown();
    }

    private function user(array $roles): User
    {
        return $this->get(UserRepository::class)->find(Fixtures::user($roles)['id']);
    }

    private function service(Response ...$responses): DeploymentService
    {
        foreach (self::ENV as $k => $v) {
            putenv("$k=$v");
        }
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $clients = new OpsClients($this->get(SettingsService::class), $stack);
        return new DeploymentService($this->db, $this->get(AccessService::class), $this->get(AuditLogger::class), $clients, $this->get(JobQueue::class), new NullLogger());
    }

    private static function json(array $d, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($d));
    }

    public function testFourEyesApprovalTriggerPollAndRollback(): void
    {
        $requester = $this->user(['platform_admin']); // has deploy.request + deploy.approve
        $approver = $this->user(['platform_admin']);
        $svc = $this->service(
            self::json(['id' => 101, 'status' => 'created', 'ref' => 'main', 'sha' => 'aaaaaaa1', 'web_url' => 'https://gitlab.test/p/-/pipelines/101'], 201),
            self::json(['id' => 101, 'status' => 'success', 'ref' => 'main', 'sha' => 'aaaaaaa1', 'web_url' => 'https://gitlab.test/p/-/pipelines/101']),
            self::json(['id' => 102, 'status' => 'created', 'ref' => 'main', 'sha' => 'bbbbbbb2', 'web_url' => 'https://gitlab.test/p/-/pipelines/102'], 201),
            self::json(['id' => 102, 'status' => 'success', 'ref' => 'main', 'sha' => 'bbbbbbb2', 'web_url' => 'https://gitlab.test/p/-/pipelines/102']),
            self::json(['id' => 103, 'status' => 'created', 'ref' => 'main', 'sha' => 'aaaaaaa1', 'web_url' => 'https://gitlab.test/p/-/pipelines/103'], 201),
            self::json(['id' => 103, 'status' => 'success', 'ref' => 'main', 'sha' => 'aaaaaaa1', 'web_url' => 'https://gitlab.test/p/-/pipelines/103']),
        );
        $v1 = 'v1.' . random_int(100, 999) . '.0';
        $v2 = 'v2.' . random_int(100, 999) . '.0';

        $id1 = $svc->request($requester, ['environment' => 'dev', 'version' => $v1]);
        try {
            $svc->approve($requester, $id1);
            self::fail('requester approved own deployment');
        } catch (AccessDeniedException $e) {
            self::assertStringContainsString('Four-eyes', $e->getMessage());
        }
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'deploy.approve', 'entity_id' => $id1, 'result' => 'denied'])->exists(), 'denial audited');
        self::assertSame('requested', $svc->get($id1)['status']);

        $r = $svc->approve($approver, $id1);
        self::assertTrue($r['triggered']);
        $d = $svc->get($id1);
        self::assertSame('running', $d['status']);
        self::assertSame(101, (int) $d['pipeline_id']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $vars = array_column($body['variables'], 'value', 'key');
        self::assertSame('dev', $vars['DEPLOY_ENV']);
        self::assertSame($v1, $vars['VERSION']);
        self::assertSame('glpat-test', $this->history[0]['request']->getHeaderLine('PRIVATE-TOKEN'));
        self::assertTrue((new Query($this->db))->from('jobs')->where(['type' => DeploymentService::POLL_JOB, 'idempotency_key' => 'deploy-poll:' . $id1 . ':0'])->exists());

        self::assertSame('succeeded', $svc->refresh($id1));

        $id2 = $svc->request($requester, ['environment' => 'dev', 'version' => $v2]);
        try {
            $svc->request($requester, ['environment' => 'dev', 'version' => 'v9']);
            self::fail('second concurrent deployment accepted');
        } catch (ConflictException) {
        }
        $svc->approve($approver, $id2);
        self::assertSame('succeeded', $svc->refresh($id2));

        $rb = $svc->requestRollback($requester, $id2);
        $rbRow = $svc->get($rb);
        self::assertSame($v1, $rbRow['version'], 'rollback targets the previous successful version');
        self::assertSame($id2, $rbRow['rollback_of']);
        $svc->approve($approver, $rb);
        self::assertSame('succeeded', $svc->refresh($rb));
        self::assertSame('rolled_back', $svc->get($id2)['status']);
    }

    public function testRejectAndPermissions(): void
    {
        $svc = $this->service();
        $sre = $this->user(['devops_sre']); // deploy.request, no deploy.approve
        $auditor = $this->user(['auditor']);
        $id = $svc->request($sre, ['environment' => 'dev', 'version' => 'v0.0.' . random_int(1, 999)]);
        try {
            $svc->approve($sre, $id);
            self::fail('devops_sre must not approve');
        } catch (AccessDeniedException) {
        }
        try {
            $svc->request($auditor, ['environment' => 'dev', 'version' => 'v1']);
            self::fail('auditor must not request');
        } catch (AccessDeniedException) {
        }
        $svc->reject($this->user(['platform_admin']), $id, 'Change freeze');
        self::assertSame('rejected', $svc->get($id)['status']);
    }

    public function testDatabaseEnforcesFourEyes(): void
    {
        $u = Fixtures::user(['platform_admin']);
        $this->expectException(\Yiisoft\Db\Exception\Exception::class);
        $this->db->createCommand()->insert('deployments', ['environment' => 'staging', 'version' => 'x', 'status' => 'approved', 'requested_by' => $u['id'], 'approved_by' => $u['id']])->execute();
    }

    public function testKubernetesScaleIsAuditedAndRequiresPermission(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv("$k=$v");
        }
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            self::json(['spec' => ['replicas' => 2]]),
            self::json(['metadata' => ['name' => 'worker'], 'spec' => ['replicas' => 4]]),
        ]));
        $stack->push(Middleware::history($history));
        $infra = new InfrastructureService($this->db, new OpsClients($this->get(SettingsService::class), $stack), $this->get(AccessService::class), $this->get(AuditLogger::class));
        $sre = $this->user(['devops_sre']);
        $r = $infra->scale($sre, 'worker', 4, 'queue backlog');
        self::assertSame(['name' => 'worker', 'replicas' => 4, 'previous' => 2], $r);
        self::assertSame('k8s-test', substr($history[1]['request']->getHeaderLine('Authorization'), 7));
        $audit = (new Query($this->db))->from('audit_log')->where(['action' => 'infra.k8s.scale', 'entity_id' => 'worker', 'actor_id' => $sre->id])->one();
        self::assertNotNull($audit);
        self::assertSame(['replicas' => 2], json_decode((string) $audit['before'], true));

        $this->expectException(AccessDeniedException::class);
        $infra->scale($this->user(['auditor']), 'worker', 1, 'x');
    }
}
