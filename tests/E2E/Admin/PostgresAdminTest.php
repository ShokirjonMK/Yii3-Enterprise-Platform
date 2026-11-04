<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * 12 · PostgreSQL Management over HTTP (diagnostics + safe operations).
 */
final class PostgresAdminTest extends TestCase
{
    /** @var resource|null */
    private $sleeper = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sleeper)) {
            proc_terminate($this->sleeper);
            proc_close($this->sleeper);
        }
        Fixtures::db()->createCommand("DELETE FROM jobs WHERE type = 'platform.maintenance.partitions' AND payload->>'_manual' = 'true' AND status = 'pending'")->execute();
        Fixtures::cleanup();
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }

    /** Starts a separate PHP process holding an application-role backend in pg_sleep(); returns its pid. */
    private function startSleeper(string $tag): int
    {
        $code = '$p = new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s;application_name=%s", getenv("DB_HOST") ?: "postgres", getenv("DB_PORT") ?: "5432", getenv("DB_NAME") ?: "platform", $argv[1]), getenv("DB_USER") ?: "platform", getenv("DB_PASSWORD"));'
            . 'try { $p->query("SELECT pg_sleep(60), \'secret-literal\'"); echo "finished"; } catch (Throwable $e) { echo "cancelled:", $e->getMessage(); }';
        $this->sleeper = proc_open([PHP_BINARY, '-r', $code, $tag], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $db = Fixtures::db();
        for ($i = 0; $i < 50; $i++) {
            $pid = (int) $db->createCommand("SELECT pid FROM pg_stat_activity WHERE application_name = :a AND state = 'active'", [':a' => $tag])->queryScalar();
            if ($pid > 0) {
                return $pid;
            }
            usleep(100_000);
        }
        self::fail('sleeper backend did not start');
    }

    public function testDiagnosticPagesRender(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $web->get('/admin/postgres');
        self::assertStringContainsString('PostgreSQL 17', $web->body());
        foreach (['/admin/postgres/activity', '/admin/postgres/activity?active=0&min=0', '/admin/postgres/queries?order=total', '/admin/postgres/storage?order=dead', '/admin/postgres/replication', '/admin/postgres/partitions'] as $p) {
            self::assertSame(200, $web->get($p)->getStatusCode(), $p);
        }
        self::assertStringContainsString('audit_log_p' . date('Ym'), $web->body());
        $web->get('/admin/postgres/queries');
        self::assertMatchesRegularExpression('/Mean ms|Not configured/', $web->body());
        $web->get('/admin/postgres/replication');
        self::assertStringContainsString('WAL archiver', $web->body());
        self::assertStringContainsString('Not configured', $web->body(), 'no DB_REPLICAS in dev');
    }

    public function testCancelOwnBackendWithStepUpAndRefuseForeignPid(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $tag = 'e2e-sleeper-' . bin2hex(random_bytes(3));
        $pid = $this->startSleeper($tag);

        $web->get('/admin/postgres/activity');
        self::assertStringContainsString((string) $pid, $web->body());
        self::assertStringNotContainsString('secret-literal', $web->body(), 'literals are masked');

        self::expireReauth($user['id']);
        $web->post('/admin/postgres/cancel', ['pid' => (string) $pid]);
        self::assertStringStartsWith('/auth/confirm', $web->url());
        AdminSession::reauth($web, $user);

        $web->get('/admin/postgres/activity');
        $web->post('/admin/postgres/cancel', ['pid' => (string) $pid]);
        $out = $this->sleeperOutput();
        self::assertStringContainsString('cancelled', (string) $out, 'the sleeping query was really cancelled');
        self::assertTrue((new Query(Fixtures::db()))->from('audit_log')->where(['action' => 'db.cancel_backend', 'entity_id' => (string) $pid, 'result' => 'success'])->exists());

        // a background process (not the application role) is refused with 403
        $foreign = (int) Fixtures::db()->createCommand("SELECT pid FROM pg_stat_activity WHERE backend_type <> 'client backend' LIMIT 1")->queryScalar();
        $web->get('/admin/postgres/activity');
        self::assertSame(403, $web->post('/admin/postgres/cancel', ['pid' => (string) $foreign])->getStatusCode());
        $web->get('/admin/postgres/activity');
        self::assertSame(422, $web->post('/admin/postgres/cancel', ['pid' => 'abc'])->getStatusCode());
    }

    private function sleeperOutput(): string
    {
        $deadline = microtime(true) + 10;
        while (proc_get_status($this->sleeper)['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }
        return (string) stream_get_contents($this->pipes[1]);
    }

    public function testMaintenanceAndPartitionJob(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $web->get('/admin/postgres/storage');
        self::assertStringContainsString('public.jobs', $web->body());
        AdminSession::reauth($web, $user);
        $web->get('/admin/postgres/storage');
        $web->post('/admin/postgres/maintenance', ['table' => 'public.jobs', 'operation' => 'vacuum']);
        self::assertStringContainsString('VACUUM (ANALYZE) completed on public.jobs', $web->body());
        $web->post('/admin/postgres/maintenance', ['table' => 'public.users; DROP TABLE users', 'operation' => 'analyze']);
        self::assertStringContainsString('Unknown table.', $web->body());
        self::assertTrue((new Query(Fixtures::db()))->from('audit_log')->where(['action' => 'db.vacuum', 'entity_id' => 'public.jobs', 'actor_id' => $user['id']])->exists());

        $web->get('/admin/postgres/partitions');
        $web->post('/admin/postgres/partitions/run');
        self::assertStringContainsString('platform.maintenance.partitions', $web->body(), 'redirected to the job detail');
    }

    public function testAuditorCannotOperate(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/postgres/storage')->getStatusCode());
        self::assertStringNotContainsString('name="operation"', $web->body());
        self::assertSame(403, $web->post('/admin/postgres/maintenance', ['table' => 'public.jobs', 'operation' => 'analyze'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/postgres/cancel', ['pid' => '1'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/postgres/partitions/run')->getStatusCode());
    }
}
