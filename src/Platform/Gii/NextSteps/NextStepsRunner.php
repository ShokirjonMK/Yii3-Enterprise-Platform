<?php

declare(strict_types=1);

namespace App\Platform\Gii\NextSteps;

use App\Platform\Audit\AuditLogger;
use App\Platform\Messaging\Kafka\KafkaAdmin;
use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaTopicCreator;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Logging\Redactor;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * "Next steps" after Enterprise Gii wrote a module, run from the admin panel instead of the console:
 *   migrate → `./yii migrate:up`   rbac → `./yii rbac:sync`   kafka → `./yii kafka:provision` + create missing topics.
 *
 * Every step runs the real console command in a separate PHP process (the same code path as the CLI, and a fresh
 * configuration — a long-running worker would not see the module's new config files). Output is streamed into
 * devtools_runs.log every ~0.4 s, so the page shows it live. Runs go through the job queue (type
 * devtools.gii.next_steps, one attempt); when no queue worker is alive the request runs them inline.
 * One run at a time; a step failure skips the remaining steps. Start and result are audited.
 *
 * Author: ShokirjonMK
 */
final class NextStepsRunner
{
    public const JOB = 'devtools.gii.next_steps';

    public const STEPS = [
        'migrate' => ['label' => 'Apply database migrations', 'cli' => './yii migrate:up'],
        'rbac' => ['label' => 'Synchronise roles and permissions', 'cli' => './yii rbac:sync'],
        'kafka' => ['label' => 'Create Kafka topics', 'cli' => './yii kafka:provision'],
    ];

    private const ARGS = [
        'migrate' => ['migrate:up', '--no-interaction', '--force-yes'],
        'rbac' => ['rbac:sync'],
        'kafka' => ['kafka:provision'],
    ];

    private const MAX_LOG = 1_000_000;
    private const STALE_MINUTES = 35;

    /** @param list<string> $migrationPaths */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly JobQueue $queue,
        private readonly KafkaAdmin $kafka,
        private readonly KafkaConfig $kafkaConfig,
        private readonly KafkaTopicCreator $topics,
        private readonly AuditLogger $audit,
        private readonly string $rootPath,
        private readonly array $migrationPaths,
    ) {}

    /**
     * Current state for the page: pending migrations, missing Kafka topics, worker availability, active run.
     *
     * @return array{pending_migrations:list<string>, kafka_enabled:bool, kafka_missing:?list<string>, kafka_unsupported:?string, worker:bool, active:?string}
     */
    public function state(): array
    {
        $missing = null;
        if ($this->kafkaConfig->enabled) {
            try {
                $rows = $this->kafka->topicDrift();
                $unknown = array_filter($rows, static fn($r) => $r['exists'] === null);
                $missing = $unknown !== [] && count($unknown) === count($rows) ? null
                    : array_values(array_map(fn($r) => $this->kafkaConfig->topic((string) $r['name']), array_filter($rows, static fn($r) => $r['exists'] === false)));
            } catch (Throwable) {
                $missing = null;
            }
        }
        return [
            'pending_migrations' => $this->pendingMigrations(),
            'kafka_enabled' => $this->kafkaConfig->enabled,
            'kafka_missing' => $missing,
            'kafka_unsupported' => $this->kafkaConfig->enabled ? $this->topics->unsupported() : null,
            'worker' => $this->workerAlive(),
            'active' => $this->active(),
        ];
    }

    /** @return list<string> migration class names that exist on disk but are not applied */
    public function pendingMigrations(): array
    {
        try {
            $applied = array_flip(array_map('strval', (new Query($this->db))->select('name')->from('migration')->column()));
        } catch (Throwable) {
            $applied = [];
        }
        $pending = [];
        foreach ($this->migrationPaths as $dir) {
            foreach (glob(rtrim($dir, '/') . '/M*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                if (!isset($applied[$name])) {
                    $pending[] = $name;
                }
            }
        }
        sort($pending);
        return $pending;
    }

    public function workerAlive(): bool
    {
        return (int) $this->db->createCommand(
            "SELECT count(*) FROM workers WHERE type = 'queue' AND status IN ('idle','busy')
               AND heartbeat_at > now() - interval '1 minute' AND (',' || queues || ',') LIKE '%,default,%'",
        )->queryScalar() > 0;
    }

    /** Id of the queued/running run, if any (runs older than 35 min are marked failed as stale). */
    public function active(): ?string
    {
        $this->db->createCommand(
            "UPDATE devtools_runs SET status = 'failed', finished_at = now(),
                    log = log || E'\\n# Marked as failed: no progress for " . self::STALE_MINUTES . " minutes (worker stopped?)\\n'
              WHERE status IN ('queued','running') AND created_at < now() - interval '" . self::STALE_MINUTES . " minutes'",
        )->execute();
        $id = (new Query($this->db))->select('id')->from('devtools_runs')->where(['status' => ['queued', 'running']])->orderBy(['created_at' => SORT_DESC])->scalar();
        return $id === false || $id === null ? null : (string) $id;
    }

    /**
     * @param list<string> $steps
     * @return array{id:string, mode:string}
     */
    public function start(string $actorId, string $subject, array $steps): array
    {
        $steps = array_values(array_intersect(array_keys(self::STEPS), $steps));
        if ($steps === []) {
            throw new ValidationException(['steps' => ['Select at least one step.']]);
        }
        if (($active = $this->active()) !== null) {
            throw new ConflictException('Another run is in progress: ' . $active, 'devtools_run_active');
        }
        $id = Uuid::v7();
        $mode = $this->workerAlive() ? 'queue' : 'inline';
        $rows = array_map(static fn(string $k) => [
            'key' => $k, 'label' => self::STEPS[$k]['label'], 'cli' => self::STEPS[$k]['cli'],
            'status' => 'pending', 'exit_code' => null, 'started_at' => null, 'finished_at' => null, 'duration_ms' => null,
        ], $steps);
        $this->db->createCommand()->insert('devtools_runs', [
            'id' => $id,
            'subject' => mb_substr($subject, 0, 80),
            'mode' => $mode,
            'steps' => new Expression('CAST(:s AS jsonb)', [':s' => json_encode($rows, JSON_THROW_ON_ERROR)]),
            'created_by' => $actorId !== '' ? $actorId : null,
        ])->execute();
        $this->audit->record('devtools.next_steps.started', 'devtools_run', $id, null, null, ['subject' => $subject, 'steps' => $steps, 'mode' => $mode], category: 'config', severity: 'notice');
        if ($mode === 'queue') {
            $jobId = $this->queue->push(self::JOB, ['run_id' => $id], ['max_attempts' => 1, 'timeout' => 1800, 'idempotency_key' => 'devtools-run-' . $id]);
            $this->db->createCommand()->update('devtools_runs', ['job_id' => $jobId], ['id' => $id])->execute();
        }
        return ['id' => $id, 'mode' => $mode];
    }

    /** Runs a queued run (from the job, or inline). No-op when it is not queued any more (idempotent). */
    public function execute(string $id): void
    {
        $claimed = $this->db->createCommand()->update('devtools_runs', ['status' => 'running', 'started_at' => new Expression('now()')], ['id' => $id, 'status' => 'queued'])->execute();
        if ($claimed === 0) {
            return;
        }
        @set_time_limit(1800);
        $run = $this->find($id);
        $steps = $run['steps'];
        $t0 = microtime(true);
        $this->append($id, sprintf("# Run %s · %s · %s\n# %s\n", $id, $run['subject'] ?: '-', $run['mode'], gmdate('Y-m-d H:i:s') . ' UTC'));
        $failed = false;
        foreach ($steps as $i => $step) {
            if ($failed) {
                $steps[$i]['status'] = 'skipped';
                continue;
            }
            $start = microtime(true);
            $steps[$i]['status'] = 'running';
            $steps[$i]['started_at'] = gmdate('c');
            $this->saveSteps($id, $steps);
            $args = self::ARGS[$step['key']] ?? [];
            $this->append($id, "\n$ ./yii " . implode(' ', $args) . "\n");
            try {
                $code = $this->runCli($id, $args);
                if ($code === 0 && $step['key'] === 'kafka') {
                    $code = $this->createTopics($id);
                }
            } catch (Throwable $e) {
                $this->append($id, 'ERROR: ' . $e->getMessage() . "\n");
                $code = 1;
            }
            $steps[$i]['status'] = $code === 0 ? 'succeeded' : 'failed';
            $steps[$i]['exit_code'] = $code;
            $steps[$i]['finished_at'] = gmdate('c');
            $steps[$i]['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            $this->append($id, sprintf("→ exit code %d (%.1f s)\n", $code, microtime(true) - $start));
            $failed = $code !== 0;
            $this->saveSteps($id, $steps);
        }
        $this->saveSteps($id, $steps);
        $status = $failed ? 'failed' : 'succeeded';
        $this->append($id, sprintf("\n# %s in %.1f s\n", strtoupper($status), microtime(true) - $t0));
        $this->db->createCommand()->update('devtools_runs', ['status' => $status, 'finished_at' => new Expression('now()')], ['id' => $id])->execute();
        $this->audit->record('devtools.next_steps.finished', 'devtools_run', $id, null, ['status' => $status], [
            'subject' => $run['subject'],
            'steps' => array_map(static fn($s) => $s['key'] . ':' . $s['status'], $steps),
        ], result: $failed ? 'failure' : 'success', category: 'config', severity: $failed ? 'warning' : 'notice', actorId: $run['created_by']);
    }

    /** @return array{id:string, subject:string, status:string, mode:string, steps:list<array<string,mixed>>, log:string, job_id:?string, created_by:?string, created_at:string, started_at:?string, finished_at:?string}|null */
    public function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = (new Query($this->db))->from('devtools_runs')->where(['id' => $id])->one();
        if ($row === null) {
            return null;
        }
        $row['steps'] = json_decode((string) $row['steps'], true) ?: [];
        return $row;
    }

    /** @return list<array<string,mixed>> latest runs without logs */
    public function recent(int $limit = 10): array
    {
        $rows = (new Query($this->db))
            ->select(['r.id', 'r.subject', 'r.status', 'r.mode', 'r.steps', 'r.created_at', 'r.finished_at', 'username' => 'u.username'])
            ->from(['r' => 'devtools_runs'])->leftJoin(['u' => 'users'], 'u.id = r.created_by')
            ->orderBy(['r.created_at' => SORT_DESC])->limit($limit)->all();
        foreach ($rows as &$r) {
            $r['steps'] = json_decode((string) $r['steps'], true) ?: [];
        }
        return $rows;
    }

    /** @param list<string> $args */
    private function runCli(string $id, array $args): int
    {
        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : PHP_BINARY; // PHP_BINARY is php-fpm under FPM
        $cmd = array_merge([$php, $this->rootPath . '/yii'], $args, ['--no-ansi']);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->rootPath);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start ' . implode(' ', $args));
        }
        try {
            stream_set_blocking($pipes[1], false);
            $buffer = '';
            $flushed = microtime(true);
            while (true) {
                $read = [$pipes[1]];
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                    $chunk = fread($pipes[1], 65536);
                    if (is_string($chunk)) {
                        $buffer .= $chunk;
                    }
                }
                if (feof($pipes[1])) {
                    break;
                }
                if ($buffer !== '' && microtime(true) - $flushed > 0.4) {
                    $this->append($id, $buffer);
                    $buffer = '';
                    $flushed = microtime(true);
                }
            }
            if ($buffer !== '') {
                $this->append($id, $buffer);
            }
            fclose($pipes[1]);
            return proc_close($proc);
        } catch (Throwable $e) {
            proc_terminate($proc);
            throw $e;
        }
    }

    /** Creates topics that are in the registry but missing in the cluster (kafka:provision only renders the script). */
    private function createTopics(string $id): int
    {
        if (!$this->kafkaConfig->enabled) {
            $this->append($id, "Kafka is disabled (KAFKA_ENABLED=false) — nothing to create.\n");
            return 0;
        }
        if (($reason = $this->topics->unsupported()) !== null) {
            $this->append($id, "Topics were not created: $reason\nRun the rendered script with Kafka's CLI: ./scripts/platform.sh kafka-provision\n");
            return 1;
        }
        $rows = $this->kafka->topicDrift();
        if ($rows !== [] && array_filter($rows, static fn($r) => $r['exists'] !== null) === []) {
            $this->append($id, "Kafka is unreachable — topics were not created.\n");
            return 1;
        }
        foreach ($rows as $r) {
            if ($r['exists'] && (int) $r['live_partitions'] < (int) $r['partitions']) {
                $this->append($id, sprintf("WARNING: %s has %d partitions, the registry wants %d (increase with kafka-topics.sh --alter).\n", $this->kafkaConfig->topic((string) $r['name']), (int) $r['live_partitions'], (int) $r['partitions']));
            }
        }
        $missing = array_values(array_filter($rows, static fn($r) => $r['exists'] === false));
        if ($missing === []) {
            $this->append($id, sprintf("\nAll %d registered topics exist — nothing to create.\n", count($rows)));
            return 0;
        }
        $this->append($id, sprintf("\nCreating %d missing topic(s) via the Kafka protocol (CreateTopics):\n", count($missing)));
        $request = array_map(fn($r) => [
            'name' => $this->kafkaConfig->topic((string) $r['name']),
            'partitions' => (int) $r['partitions'],
            'replication_factor' => (int) $r['replication_factor'],
            'configs' => ['retention.ms' => (string) (int) $r['retention_ms'], 'cleanup.policy' => (string) $r['cleanup_policy']],
        ], $missing);
        $result = $this->topics->create($request);
        $ok = 0;
        foreach ($request as $i => $t) {
            $res = $result[$t['name']] ?? ['code' => -1, 'error' => 'NO_RESPONSE', 'message' => null];
            $good = in_array($res['code'], [0, KafkaTopicCreator::TOPIC_ALREADY_EXISTS], true);
            $ok += $good ? 1 : 0;
            $this->append($id, sprintf("  %-36s %s\n", $t['name'], $res['code'] === 0
                ? sprintf('created (partitions=%d, replication=%d)', $t['partitions'], $t['replication_factor'])
                : $res['error'] . ($res['message'] ? ' — ' . $res['message'] : '')));
            if ($good) {
                $this->db->createCommand()->update('kafka_topics', ['provisioned_at' => new Expression('now()')], ['name' => $missing[$i]['name']])->execute();
            }
        }
        $this->append($id, sprintf("%d of %d topic(s) ready.\n", $ok, count($request)));
        return $ok === count($request) ? 0 : 1;
    }

    private function append(string $id, string $text): void
    {
        $text = Redactor::redactString((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text));
        $this->db->createCommand(
            'UPDATE devtools_runs SET log = right(log || :t, ' . self::MAX_LOG . ') WHERE id = :id',
            [':t' => $text, ':id' => $id],
        )->execute();
    }

    /** @param list<array<string,mixed>> $steps */
    private function saveSteps(string $id, array $steps): void
    {
        $this->db->createCommand(
            'UPDATE devtools_runs SET steps = CAST(:s AS jsonb) WHERE id = :id',
            [':s' => json_encode(array_values($steps), JSON_THROW_ON_ERROR), ':id' => $id],
        )->execute();
    }
}
