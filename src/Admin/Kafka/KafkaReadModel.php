<?php

declare(strict_types=1);

namespace App\Admin\Kafka;

use App\Platform\Audit\AuditLogger;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Platform\Database\SqlSearch;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Database side of Admin → Kafka: topic registry CRUD, consumer pause state, outbox and dead letters.
 * Every change is audited here (the runner/relay classes do not audit operator actions themselves).
 */
final class KafkaReadModel
{
    public const OUTBOX_SORT = ['created_at' => 'created_at', 'attempts' => 'attempts', 'topic' => 'topic', 'event_type' => 'event_type', 'available_at' => 'available_at'];
    public const DLQ_SORT = ['failed_at' => 'failed_at', 'topic' => 'topic', 'consumer_group' => 'consumer_group', 'attempts' => 'attempts'];
    private const TOPIC_NAME = '/^[a-z0-9][a-z0-9._-]{0,200}$/';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ topic registry

    /** @return array<string,mixed> */
    public function topic(string $name): array
    {
        return (new Query($this->db))->from('kafka_topics')->where(['name' => $name])->one() ?? throw new NotFoundException('Topic not found in the registry.');
    }

    public function saveTopic(?string $existing, array $input, ?string $actorId): string
    {
        $name = $existing ?? strtolower(trim((string) ($input['name'] ?? '')));
        $errors = [];
        if (!preg_match(self::TOPIC_NAME, $name)) {
            $errors['name'][] = 'Topic name: lowercase letters, digits, . _ - (max 200).';
        }
        $partitions = filter_var($input['partitions'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $rf = filter_var($input['replication_factor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        $retentionHours = filter_var($input['retention_hours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -1, 'max_range' => 87600]]);
        $policy = (string) ($input['cleanup_policy'] ?? 'delete');
        if ($partitions === false) {
            $errors['partitions'][] = 'Partitions must be between 1 and 1000.';
        }
        if ($rf === false) {
            $errors['replication_factor'][] = 'Replication factor must be between 1 and 7.';
        }
        if ($retentionHours === false || $retentionHours === 0) {
            $errors['retention_hours'][] = 'Retention: hours (1–87600) or -1 for unlimited.';
        }
        if (!in_array($policy, ['delete', 'compact', 'compact,delete'], true)) {
            $errors['cleanup_policy'][] = 'Unknown cleanup policy.';
        }
        $before = $existing === null ? null : $this->topic($existing);
        if ($before !== null && $partitions !== false && $partitions < (int) $before['partitions']) {
            $errors['partitions'][] = 'Kafka cannot reduce the number of partitions.';
        }
        if ($existing === null && $errors === [] && (new Query($this->db))->from('kafka_topics')->where(['name' => $name])->exists()) {
            $errors['name'][] = 'A topic with this name is already registered.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $row = [
            'partitions' => $partitions,
            'replication_factor' => $rf,
            'retention_ms' => $retentionHours === -1 ? -1 : $retentionHours * 3_600_000,
            'cleanup_policy' => $policy,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 1000),
        ];
        $this->db->transaction(function () use ($existing, $name, $row, $before, $actorId): void {
            if ($existing === null) {
                $this->db->createCommand()->insert('kafka_topics', ['name' => $name] + $row)->execute();
                $this->audit->record('kafka.topic.registered', 'kafka_topic', $name, null, $row, category: 'operations', actorId: $actorId);
            } else {
                $this->db->createCommand()->update('kafka_topics', $row, ['name' => $name])->execute();
                $this->audit->record('kafka.topic.updated', 'kafka_topic', $name, $before, $row, category: 'operations', actorId: $actorId);
            }
        });
        return $name;
    }

    /** Removes a topic from the registry only — the live topic (and its data) is never deleted from the UI. */
    public function unregisterTopic(string $name, ?string $actorId): void
    {
        $before = $this->topic($name);
        $this->db->transaction(function () use ($name, $before, $actorId): void {
            $this->db->createCommand()->delete('kafka_topics', ['name' => $name])->execute();
            $this->audit->record('kafka.topic.unregistered', 'kafka_topic', $name, $before, null, category: 'operations', severity: 'notice', actorId: $actorId);
        });
    }

    // ------------------------------------------------------------------ consumer groups

    /** @return array<string, array{paused:bool, paused_by:?string, paused_at:?string}> */
    public function consumerStates(): array
    {
        $out = [];
        foreach ((new Query($this->db))->from('consumer_state')->all() as $r) {
            $out[(string) $r['consumer_group']] = ['paused' => Sql::bool($r['paused']), 'paused_by' => $r['paused_by'], 'paused_at' => $r['paused_at']];
        }
        return $out;
    }

    /** @return array<string, list<array<string,mixed>>> group => consumer processes (from the workers registry) */
    public function consumerProcesses(): array
    {
        $out = [];
        foreach ((new Query($this->db))
            ->select(['id', 'hostname', 'pid', 'queues', 'status', 'heartbeat_at', 'alive' => new Expression("heartbeat_at > now() - interval '90 seconds' AND status NOT IN ('stopped','lost')")])
            ->from('workers')->where(['type' => 'kafka'])->orderBy(['heartbeat_at' => SORT_DESC])->all() as $w) {
            $w['alive'] = Sql::bool($w['alive']);
            $out[(string) $w['queues']][] = $w;
        }
        return $out;
    }

    /** The runner polls consumer_state.paused every 5 s and pauses its assigned partitions. */
    public function setConsumerPaused(string $group, bool $paused, ?string $actorId): void
    {
        $this->db->createCommand()->upsert('consumer_state', [
            'consumer_group' => $group,
            'paused' => $paused,
            'paused_by' => $paused ? $actorId : null,
            'paused_at' => $paused ? new Expression('now()') : null,
            'updated_at' => new Expression('now()'),
        ])->execute();
        $this->audit->record($paused ? 'kafka.consumer.paused' : 'kafka.consumer.resumed', 'kafka_consumer_group', $group, category: 'operations', severity: 'notice', actorId: $actorId);
    }

    /** @return list<string> topics a group reads (retry group reads the retry topics) */
    public static function groupTopics(string $group, array $def): array
    {
        return $group === 'platform-retry' ? array_keys(KafkaConsumerRunner::RETRY_TOPICS) : array_values($def['topics'] ?? []);
    }

    // ------------------------------------------------------------------ outbox

    /** @return array{pending:int, failed:int, published_24h:int, oldest_pending:?string} */
    public function outboxCounts(): array
    {
        $r = $this->db->createCommand(<<<'SQL'
            SELECT count(*) FILTER (WHERE status = 'pending') AS pending,
                   count(*) FILTER (WHERE status = 'failed') AS failed,
                   count(*) FILTER (WHERE status = 'published' AND published_at > now() - interval '24 hours') AS published_24h,
                   min(created_at) FILTER (WHERE status = 'pending') AS oldest_pending
            FROM outbox
            SQL)->queryOne() ?? [];
        return ['pending' => (int) ($r['pending'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0), 'published_24h' => (int) ($r['published_24h'] ?? 0), 'oldest_pending' => $r['oldest_pending'] ?? null];
    }

    public function outbox(string $status, string $q, int $page, ?string $sort): Page
    {
        $query = (new Query($this->db))
            ->select(['id', 'topic', 'event_type', 'aggregate_type', 'aggregate_id', 'status', 'attempts', 'available_at', 'published_at', 'created_at', 'last_error' => new Expression('left(last_error, 300)')])
            ->from('outbox');
        if (in_array($status, ['pending', 'failed', 'published'], true)) {
            $query->andWhere(['status' => $status]);
        }
        if ($q !== '') {
            Uuid::isValid($q) ? $query->andWhere(['id' => $q]) : $query->andWhere(SqlSearch::contains($q, ['topic', 'event_type', 'aggregate_type', 'aggregate_id']));
        }
        return Paginator::paginate($query, $page, 25, $sort, self::OUTBOX_SORT, '-created_at')->map(static function (array $r): array {
            $r['last_error'] = $r['last_error'] === null ? null : Redactor::redactString((string) $r['last_error']);
            return $r;
        });
    }

    public function requeueOutboxRow(string $id, ?string $actorId): void
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundException('Outbox event not found.');
        }
        $n = $this->db->createCommand("UPDATE outbox SET status = 'pending', attempts = 0, available_at = now() WHERE id = :id AND status = 'failed'", [':id' => $id])->execute();
        if ($n === 0) {
            throw new ConflictException('Only failed outbox events can be re-queued.', 'invalid_state');
        }
        $this->audit->record('outbox.requeued', 'outbox', $id, category: 'operations', severity: 'notice', actorId: $actorId);
    }

    // ------------------------------------------------------------------ dead letters

    public function deadLetters(string $state, string $group, string $q, int $page, ?string $sort): Page
    {
        $query = (new Query($this->db))
            ->select(['id', 'consumer_group', 'topic', 'partition', 'offset', 'message_key', 'message_id', 'attempts', 'failed_at', 'replayed_at', 'error' => new Expression('left(error, 300)')])
            ->from('kafka_dead_letters');
        if ($state === 'open') {
            $query->andWhere(['replayed_at' => null]);
        } elseif ($state === 'replayed') {
            $query->andWhere(['not', ['replayed_at' => null]]);
        }
        if ($group !== '') {
            $query->andWhere(['consumer_group' => $group]);
        }
        if ($q !== '') {
            $query->andWhere(SqlSearch::contains($q, ['topic', 'message_key', 'message_id', 'error']));
        }
        return Paginator::paginate($query, $page, 25, $sort, self::DLQ_SORT, '-failed_at')->map(static function (array $r): array {
            $r['error'] = Redactor::redactString((string) $r['error']);
            return $r;
        });
    }

    /** @return array<string,mixed> dead letter with redacted payload/headers */
    public function deadLetter(int $id): array
    {
        $row = (new Query($this->db))->from('kafka_dead_letters')->where(['id' => $id])->one() ?? throw new NotFoundException('Dead letter not found.');
        $decoded = json_decode((string) $row['payload'], true);
        $row['payload_view'] = is_array($decoded) ? Redactor::redact($decoded) : Redactor::redactString(mb_substr((string) $row['payload'], 0, 20000));
        $row['headers_view'] = Redactor::redact(Sql::json($row['headers'], []));
        $row['error'] = Redactor::redactString((string) $row['error']);
        return $row;
    }

    /** @return list<string> */
    public function deadLetterGroups(): array
    {
        return array_map('strval', $this->db->createCommand('SELECT DISTINCT consumer_group FROM kafka_dead_letters ORDER BY 1')->queryColumn());
    }
}
