<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Incidents\IncidentService;
use App\Platform\Monitoring\HealthProbeJob;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

final class OpsIncidentsTest extends IntegrationTestCase
{
    private IncidentService $incidents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incidents = $this->get(IncidentService::class);
    }

    private function user(array $roles): User
    {
        $u = Fixtures::user($roles);
        return $this->get(UserRepository::class)->find($u['id']);
    }

    public function testLifecycleTimelineNotificationsAndRules(): void
    {
        $sre = $this->user(['devops_sre']);
        $id = $this->incidents->open($sre, ['title' => 'Checkout latency', 'severity' => 'sev2', 'service' => 'api', 'description' => 'p99 > 3 s']);
        $inc = $this->incidents->get($id);
        self::assertSame('open', $inc['status']);

        // on-call (incidents.manage holders, incl. the SRE) got an in-app notification with template incident.opened
        self::assertContains($sre->id, $this->incidents->onCallUserIds(1000));
        self::assertTrue((new Query($this->db))->from('notifications')->where(['user_id' => $sre->id, 'template_code' => 'incident.opened', 'channel' => 'inapp'])->exists());

        $this->incidents->acknowledge($sre, $id);
        $inc = $this->incidents->get($id);
        self::assertSame('investigating', $inc['status']);
        self::assertSame($sre->id, $inc['commander_id']);
        $this->expectExceptionOnce(ConflictException::class, fn() => $this->incidents->acknowledge($sre, $id));

        $this->incidents->transition($sre, $id, 'identified', 'Bad index');
        $this->incidents->addNote($sre, $id, 'Rolling back index change');
        $this->incidents->transition($sre, $id, 'monitoring');
        $this->incidents->transition($sre, $id, 'resolved');
        self::assertNotNull($this->incidents->get($id)['resolved_at']);

        $this->expectExceptionOnce(ValidationException::class, fn() => $this->incidents->transition($sre, $id, 'closed'), 'sev2 needs a postmortem');
        $this->incidents->savePostmortem($sre, $id, "Root cause: missing index\nAction: add migration check");
        $this->incidents->transition($sre, $id, 'closed');
        $this->expectExceptionOnce(ConflictException::class, fn() => $this->incidents->transition($sre, $id, 'investigating'), 'closed is final');

        $types = array_column($this->incidents->timeline($id), 'type');
        self::assertSame(['opened', 'notified', 'acknowledged', 'status', 'note', 'status', 'status', 'postmortem', 'status'], $types);
        self::assertSame(4, (int) (new Query($this->db))->from('audit_log')->where(['entity_type' => 'incident', 'entity_id' => $id, 'action' => 'incident.status_changed'])->count());
    }

    public function testAuditorCannotManage(): void
    {
        $auditor = $this->user(['auditor']);
        $this->expectException(AccessDeniedException::class);
        $this->incidents->open($auditor, ['title' => 'x', 'severity' => 'sev4']);
    }

    public function testHealthProbeOpensAfterTwoFailuresAndResolves(): void
    {
        $fp = 'health:postgres';
        $this->db->createCommand("UPDATE incidents SET status = 'closed', closed_at = now() WHERE fingerprint = :f AND status NOT IN ('resolved','closed')", [':f' => $fp])->execute();
        $this->db->createCommand('DELETE FROM health_probe_state')->execute();
        /** @var HealthProbeJob $probe */
        $probe = $this->get(HealthProbeJob::class);
        $down = ['postgres' => ['status' => 'down', 'latency_ms' => 3000.0, 'details' => ['error' => 'connection refused']], 'mailer' => ['status' => 'not_configured', 'latency_ms' => 0.1, 'details' => []]];
        $up = ['postgres' => ['status' => 'up', 'latency_ms' => 1.2, 'details' => []]];

        $r1 = $probe->probe($down);
        self::assertSame([], $r1['opened'], 'one failure is not enough');
        self::assertNull($this->incidents->openByFingerprint($fp));

        $r2 = $probe->probe($down);
        self::assertCount(1, $r2['opened']);
        $id = $r2['opened'][0];
        $inc = $this->incidents->get($id);
        self::assertSame('sev1', $inc['severity']);
        self::assertSame('auto', $inc['source']);
        self::assertSame($fp, $inc['fingerprint']);

        $r3 = $probe->probe($down);
        self::assertSame([], $r3['opened'], 'no duplicate while open');
        self::assertSame(3, (int) $this->db->createCommand("SELECT consecutive_failures FROM health_probe_state WHERE component = 'postgres'")->queryScalar());

        $r4 = $probe->probe($up);
        self::assertSame([$id], $r4['resolved']);
        self::assertSame('resolved', $this->incidents->get($id)['status']);
        self::assertSame(0, (int) $this->db->createCommand("SELECT consecutive_failures FROM health_probe_state WHERE component = 'postgres'")->queryScalar());
    }

    public function testHealthProbeAgainstRealDependencies(): void
    {
        $r = $this->get(HealthProbeJob::class)->probe();
        self::assertSame('up', $r['results']['postgres']['status']);
        self::assertTrue((new Query($this->db))->from('health_probe_state')->where(['component' => 'postgres', 'status' => 'up'])->exists());
    }

    private function expectExceptionOnce(string $class, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            self::fail('Expected ' . $class . ($message ? " ($message)" : ''));
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $message);
        }
    }
}
