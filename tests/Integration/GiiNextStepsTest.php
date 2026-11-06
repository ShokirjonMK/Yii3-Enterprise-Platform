<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Audit\AuditLogger;
use App\Platform\Gii\NextSteps\NextStepsRunner;
use App\Platform\Messaging\Kafka\KafkaAdmin;
use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaTopicCreator;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use Throwable;

/**
 * Enterprise Gii "Next steps": the runner executes the real console commands in a child process, streams the
 * output into devtools_runs, records per-step status / exit code, skips the remaining steps after a failure,
 * allows one active run at a time; KafkaTopicCreator creates topics through the Kafka protocol.
 */
final class GiiNextStepsTest extends IntegrationTestCase
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        $this->db->createCommand('DELETE FROM devtools_runs')->execute();
        if ($this->tmp !== null) {
            array_map('unlink', glob($this->tmp . '/*/*') ?: []);
            @unlink($this->tmp . '/yii');
            @rmdir($this->tmp . '/migrations');
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    public function testRbacStepRunsTheConsoleCommandAndRecordsTheLog(): void
    {
        $runner = $this->get(NextStepsRunner::class);
        $run = $runner->start('', 'TestModule', ['rbac', 'unknown']);
        self::assertSame('inline', $run['mode'], 'no queue worker in the test database');
        $runner->execute($run['id']);

        $row = $runner->find($run['id']);
        self::assertSame('succeeded', $row['status'], $row['log']);
        self::assertCount(1, $row['steps']);
        self::assertSame(['rbac', 'succeeded', 0], [$row['steps'][0]['key'], $row['steps'][0]['status'], $row['steps'][0]['exit_code']]);
        self::assertStringContainsString('$ ./yii rbac:sync', $row['log']);
        self::assertStringContainsString('SUCCEEDED', $row['log']);
        self::assertNotNull($row['finished_at']);
        $runner->execute($run['id']); // idempotent: already finished
        self::assertSame(1, substr_count((string) $runner->find($run['id'])['log'], '$ ./yii rbac:sync'));

        $audit = $this->db->createCommand("SELECT count(*) FROM audit_log WHERE entity_id = :id AND action LIKE 'devtools.next_steps.%'", [':id' => $run['id']])->queryScalar();
        self::assertSame(2, (int) $audit);
    }

    public function testFailedStepStopsTheRunAndSkipsTheRest(): void
    {
        $runner = $this->runnerWithFakeConsole("<?php echo \"fake console: \" . implode(' ', array_slice(\$argv, 1)) . \"\\n\"; exit(\$argv[1] === 'migrate:up' ? 3 : 0);\n");
        $run = $runner->start('', 'Broken', ['migrate', 'rbac']);
        $runner->execute($run['id']);
        $row = $runner->find($run['id']);
        self::assertSame('failed', $row['status']);
        self::assertSame(['failed', 'skipped'], array_column($row['steps'], 'status'));
        self::assertSame(3, $row['steps'][0]['exit_code']);
        self::assertStringContainsString('fake console: migrate:up --no-interaction --force-yes --no-ansi', $row['log']);
        self::assertStringContainsString('exit code 3', $row['log']);
    }

    public function testOneActiveRunAtATimeAndValidation(): void
    {
        $runner = $this->get(NextStepsRunner::class);
        try {
            $runner->start('', 'x', []);
            self::fail('no steps must be rejected');
        } catch (ValidationException) {
        }
        $first = $runner->start('', 'First', ['rbac']);
        self::assertSame($first['id'], $runner->active());
        $this->expectException(ConflictException::class);
        $runner->start('', 'Second', ['rbac']);
    }

    public function testStaleRunIsMarkedFailedAndPendingMigrationsAreListed(): void
    {
        $this->db->createCommand()->insert('devtools_runs', ['id' => $id = Uuid::v7(), 'status' => 'running', 'created_at' => '2020-01-01 00:00:00+00'])->execute();
        $runner = $this->runnerWithFakeConsole('<?php exit(0);');
        self::assertNull($runner->active());
        self::assertSame('failed', $runner->find($id)['status']);

        file_put_contents($this->tmp . '/migrations/M991231000000NotApplied.php', '<?php');
        self::assertSame(['M991231000000NotApplied'], $runner->pendingMigrations());
        self::assertNotContains('M261105000004DevtoolsRuns', $this->get(NextStepsRunner::class)->pendingMigrations());
    }

    public function testKafkaTopicCreatorCreatesTopicsThroughTheProtocol(): void
    {
        $config = $this->get(KafkaConfig::class);
        $creator = new KafkaTopicCreator($config);
        if ($creator->unsupported() !== null) {
            self::markTestSkipped((string) $creator->unsupported());
        }
        $name = $config->topic('test.creator-' . bin2hex(random_bytes(4)));
        try {
            $first = $creator->create([['name' => $name, 'partitions' => 2, 'replication_factor' => 1, 'configs' => ['retention.ms' => '60000']]]);
        } catch (Throwable $e) {
            self::markTestSkipped('Kafka unreachable: ' . $e->getMessage());
        }
        try {
            self::assertSame(0, $first[$name]['code'], (string) $first[$name]['message']);
            $second = $creator->create([['name' => $name, 'partitions' => 2, 'replication_factor' => 1]]);
            self::assertSame(KafkaTopicCreator::TOPIC_ALREADY_EXISTS, $second[$name]['code']);
            $bad = $creator->create([['name' => $name . '-bad', 'partitions' => 1, 'replication_factor' => 9]]);
            self::assertSame('INVALID_REPLICATION_FACTOR', $bad[$name . '-bad']['error']);
            usleep(500_000);
            $meta = (new KafkaAdmin($config, $this->db))->metadata();
            self::assertSame(2, $meta['topics'][$name]['partitions'] ?? null);
        } finally {
            self::assertSame(0, $creator->delete([$name])[$name] ?? null);
        }
    }

    private function runnerWithFakeConsole(string $yii): NextStepsRunner
    {
        $this->tmp = sys_get_temp_dir() . '/next-steps-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/migrations', 0777, true);
        file_put_contents($this->tmp . '/yii', $yii);
        return new NextStepsRunner(
            $this->db,
            $this->get(JobQueue::class),
            $this->get(KafkaAdmin::class),
            $this->get(KafkaConfig::class),
            $this->get(KafkaTopicCreator::class),
            $this->get(AuditLogger::class),
            $this->tmp,
            [$this->tmp . '/migrations'],
        );
    }
}
