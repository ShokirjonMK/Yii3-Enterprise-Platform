<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Queue\JobQueue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * 10 · Jobs & Scheduler over HTTP (nginx → PHP-FPM → dev database).
 */
final class JobsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM jobs WHERE queue LIKE 'e2e-%'")->execute();
        $db->createCommand("DELETE FROM queue_state WHERE queue LIKE 'e2e-%'")->execute();
        $db->createCommand("DELETE FROM schedules WHERE code LIKE 'e2e.%'")->execute();
        Fixtures::cleanup();
    }

    private function deadJob(string $queue): string
    {
        /** @var JobQueue $q */
        $q = TestContainer::service(JobQueue::class);
        $id = $q->push('platform.queue.reap', ['api_token' => 'super-secret-value', 'n' => 1], ['queue' => $queue, 'max_attempts' => 1]);
        $job = $q->reserve([$queue], 'e2e-worker');
        $q->fail($job, new \RuntimeException('boom password=hunter2'), 3, 'e2e-worker');
        return $id;
    }

    public function testPagesRenderForOperator(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $id = $this->deadJob('e2e-a');
        foreach (['/admin/jobs', '/admin/jobs/failed', '/admin/jobs/workers', '/admin/jobs/schedules', '/admin/jobs/schedules/new', '/admin/jobs?queue=e2e-a&status=dead&q=reap'] as $path) {
            $r = $web->get($path);
            self::assertSame(200, $r->getStatusCode(), $path . ': ' . substr(strip_tags($web->body()), 0, 300));
        }
        self::assertStringContainsString(substr($id, 0, 8), $web->body(), 'filtered list shows the job');

        $r = $web->htmx('GET', '/admin/jobs?queue=e2e-a');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('id="jobs-live"', $web->body());
        self::assertStringNotContainsString('<html', $web->body(), 'HTMX gets the main region only');

        $web->get('/admin/jobs/' . $id);
        self::assertStringContainsString('platform.queue.reap', $web->body());
        self::assertStringNotContainsString('super-secret-value', $web->body(), 'payload is redacted');
        self::assertStringNotContainsString('hunter2', $web->body(), 'error is redacted');
        self::assertStringContainsString('e2e-worker', $web->body(), 'attempts are listed');

        $r = $web->htmx('GET', '/admin/jobs/schedules/preview?cron_expression=' . rawurlencode('0 9 * * 1-5') . '&timezone=Asia/Tashkent');
        self::assertStringContainsString('09:00', $web->body());
        $web->htmx('GET', '/admin/jobs/schedules/preview?cron_expression=nonsense&timezone=Asia/Tashkent');
        self::assertStringContainsString('field__error', $web->body());
    }

    public function testRetryCancelBulkAndPause(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $id = $this->deadJob('e2e-b');
        $web->get('/admin/jobs/' . $id);
        $web->post('/admin/jobs/' . $id . '/retry');
        $db = Fixtures::db();
        self::assertSame('pending', (new Query($db))->select('status')->from('jobs')->where(['id' => $id])->scalar());

        $web->get('/admin/jobs/' . $id);
        $web->post('/admin/jobs/' . $id . '/cancel');
        self::assertSame('cancelled', (new Query($db))->select('status')->from('jobs')->where(['id' => $id])->scalar());
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'job.cancelled', 'entity_id' => $id, 'actor_id' => $user['id']])->exists());

        $a = $this->deadJob('e2e-b');
        $b = $this->deadJob('e2e-b');
        $web->get('/admin/jobs/failed?queue=e2e-b');
        $web->post('/admin/jobs/retry-bulk', ['scope' => 'selected', 'ids' => [$a, $b]]);
        self::assertSame(2, (int) (new Query($db))->from('jobs')->where(['id' => [$a, $b], 'status' => 'pending'])->count());

        $c = $this->deadJob('e2e-b');
        $web->get('/admin/jobs/failed?queue=e2e-b');
        $web->post('/admin/jobs/retry-bulk', ['scope' => 'filter', 'queue' => 'e2e-b', 'status' => '', 'type' => '', 'q' => '']);
        self::assertSame('pending', (new Query($db))->select('status')->from('jobs')->where(['id' => $c])->scalar());

        $web->get('/admin/jobs');
        $web->post('/admin/jobs/queues/e2e-b/pause', ['paused' => '1']);
        self::assertTrue((bool) (new Query($db))->select('paused')->from('queue_state')->where(['queue' => 'e2e-b'])->scalar());
        $web->get('/admin/jobs');
        $web->post('/admin/jobs/queues/e2e-b/pause', ['paused' => '0']);
        self::assertFalse((bool) (new Query($db))->select('paused')->from('queue_state')->where(['queue' => 'e2e-b'])->scalar());
    }

    public function testScheduleCreateEditToggleRunNow(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $code = 'e2e.' . bin2hex(random_bytes(3));
        $web->get('/admin/jobs/schedules/new');
        $r = $web->post('/admin/jobs/schedules/new', ['code' => $code, 'name' => 'E2E', 'cron_expression' => 'not a cron', 'timezone' => 'Asia/Tashkent', 'job_type' => 'platform.queue.reap', 'queue' => 'e2e-s', 'payload' => '{}', 'overlap' => 'skip']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Invalid cron expression.', $web->body());

        $web->post('/admin/jobs/schedules/new', ['code' => $code, 'name' => 'E2E', 'cron_expression' => '*/30 * * * *', 'timezone' => 'Asia/Tashkent', 'job_type' => 'platform.queue.reap', 'queue' => 'e2e-s', 'payload' => '{"a":1}', 'overlap' => 'skip']);
        $db = Fixtures::db();
        $sid = (string) (new Query($db))->select('id')->from('schedules')->where(['code' => $code])->scalar();
        self::assertNotSame('', $sid);
        self::assertStringContainsString('/edit', $web->url());

        $web->post('/admin/jobs/schedules/' . $sid . '/edit', ['name' => 'E2E 2', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC', 'job_type' => 'platform.queue.reap', 'queue' => 'e2e-s', 'payload' => '{}', 'overlap' => 'allow', 'enabled' => '1']);
        $row = (new Query($db))->from('schedules')->where(['id' => $sid])->one();
        self::assertSame('0 3 * * *', $row['cron_expression']);
        self::assertSame('allow', $row['overlap']);

        $web->get('/admin/jobs/schedules');
        $web->post('/admin/jobs/schedules/' . $sid . '/toggle', ['enabled' => '0']);
        self::assertFalse((bool) (new Query($db))->select('enabled')->from('schedules')->where(['id' => $sid])->scalar());

        $web->get('/admin/jobs/schedules');
        $web->post('/admin/jobs/schedules/' . $sid . '/run');
        self::assertSame(1, (int) (new Query($db))->from('jobs')->where(['queue' => 'e2e-s'])->count());
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'schedule.run_now', 'entity_id' => $sid])->exists());
    }

    public function testAuditorIsReadOnly(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $id = $this->deadJob('e2e-c');
        self::assertSame(200, $web->get('/admin/jobs')->getStatusCode());
        self::assertStringNotContainsString('/pause', $web->body(), 'no manage controls for auditors');
        self::assertSame(200, $web->get('/admin/jobs/' . $id)->getStatusCode());
        self::assertSame(403, $web->post('/admin/jobs/' . $id . '/retry')->getStatusCode());
        self::assertSame(403, $web->post('/admin/jobs/queues/e2e-c/pause', ['paused' => '1'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/jobs/retry-bulk', ['scope' => 'selected', 'ids' => [$id]])->getStatusCode());
        self::assertSame(403, $web->get('/admin/jobs/schedules/new')->getStatusCode());
        self::assertSame('dead', (new Query(Fixtures::db()))->select('status')->from('jobs')->where(['id' => $id])->scalar());
    }
}
