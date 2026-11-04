<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * 11 · Kafka Management over HTTP against the running stack (real broker kafka:9092).
 */
final class KafkaAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand("DELETE FROM kafka_topics WHERE name LIKE 'e2e.%'")->execute();
        $db->createCommand("DELETE FROM kafka_dead_letters WHERE consumer_group = 'e2e-group'")->execute();
        $db->createCommand("DELETE FROM outbox WHERE aggregate_type = 'e2e'")->execute();
        $db->createCommand("UPDATE consumer_state SET paused = false WHERE consumer_group = 'platform-default'")->execute();
        Fixtures::cleanup();
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }

    private function deadLetter(): int
    {
        return (int) Fixtures::db()->createCommand()->insertReturningPks('kafka_dead_letters', [
            'consumer_group' => 'e2e-group', 'topic' => 'platform.users', 'partition' => 0, 'offset' => 1, 'message_key' => 'k-e2e',
            'message_id' => 'e2e-' . bin2hex(random_bytes(4)),
            'payload' => json_encode(['id' => 'x', 'type' => 'e2e.test', 'payload' => ['password' => 'Sup3rSecret!', 'n' => 1]]),
            'headers' => json_encode(['event_id' => 'e2e', 'authorization' => 'Bearer abc.def.ghi']),
            'error' => 'handler failed token=leaky-token', 'attempts' => 3,
        ])['id'];
    }

    private function failedOutbox(): string
    {
        $id = \App\Shared\Domain\Uuid::v7();
        Fixtures::db()->createCommand()->insert('outbox', [
            'id' => $id, 'aggregate_type' => 'e2e', 'aggregate_id' => 'a1', 'event_type' => 'e2e.happened', 'topic' => 'platform.users',
            'partition_key' => 'a1', 'payload' => json_encode(['id' => $id]), 'status' => 'failed', 'attempts' => 20, 'last_error' => 'broker down',
        ])->execute();
        return $id;
    }

    public function testClusterPagesShowLiveState(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $r = $web->get('/admin/kafka');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('kafka:9092', $web->body());
        self::assertStringContainsString('platform.users', $web->body(), 'live topic list from broker metadata');
        self::assertStringNotContainsString('Kafka unreachable', $web->body());

        foreach (['/admin/kafka/consumers', '/admin/kafka/outbox', '/admin/kafka/dead-letters', '/admin/kafka/provision'] as $p) {
            self::assertSame(200, $web->get($p)->getStatusCode(), $p);
        }
        self::assertStringContainsString('kafka-topics.sh --bootstrap-server', $web->body());
        $r = $web->get('/admin/kafka/provision/download?rf=3');
        self::assertStringContainsString('attachment', $r->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('--replication-factor 3', $web->body());

        $r = $web->htmx('GET', '/admin/kafka/consumers/platform-default/lag');
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('/High watermark|No partitions/', $web->body());
    }

    public function testTopicRegistryCrud(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $name = 'e2e.' . bin2hex(random_bytes(3));
        $web->get('/admin/kafka/topics/new');
        $r = $web->post('/admin/kafka/topics/new', ['name' => 'Bad Name!', 'partitions' => '0', 'replication_factor' => '1', 'retention_hours' => '24', 'cleanup_policy' => 'delete']);
        self::assertSame(422, $r->getStatusCode());

        $web->post('/admin/kafka/topics/new', ['name' => $name, 'partitions' => '2', 'replication_factor' => '1', 'retention_hours' => '24', 'cleanup_policy' => 'delete', 'description' => 'e2e']);
        $db = Fixtures::db();
        self::assertSame(86_400_000, (int) (new Query($db))->select('retention_ms')->from('kafka_topics')->where(['name' => $name])->scalar());
        self::assertStringContainsString($name, $web->body());
        self::assertStringContainsString('Missing', $web->body(), 'drift: registered but not on the cluster');

        $web->get('/admin/kafka/topics/' . $name . '/edit');
        $r = $web->post('/admin/kafka/topics/' . $name . '/edit', ['partitions' => '1', 'replication_factor' => '1', 'retention_hours' => '24', 'cleanup_policy' => 'delete']);
        self::assertSame(422, $r->getStatusCode(), 'partitions cannot shrink');
        $web->post('/admin/kafka/topics/' . $name . '/edit', ['partitions' => '6', 'replication_factor' => '1', 'retention_hours' => '-1', 'cleanup_policy' => 'compact']);
        self::assertSame(6, (int) (new Query($db))->select('partitions')->from('kafka_topics')->where(['name' => $name])->scalar());

        $web->get('/admin/kafka/provision');
        self::assertStringContainsString("--topic '$name' --partitions 6", $web->body());

        // unregister requires step-up
        $web->get('/admin/kafka');
        self::expireReauth($user['id']);
        $web->post('/admin/kafka/topics/' . $name . '/delete');
        self::assertStringStartsWith('/auth/confirm', $web->url());
        self::assertTrue((new Query($db))->from('kafka_topics')->where(['name' => $name])->exists());
        AdminSession::reauth($web, $user);
        $web->get('/admin/kafka');
        $web->post('/admin/kafka/topics/' . $name . '/delete');
        self::assertFalse((new Query($db))->from('kafka_topics')->where(['name' => $name])->exists());
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'kafka.topic.unregistered', 'entity_id' => $name])->exists());
    }

    public function testConsumerPauseResume(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $db = Fixtures::db();
        $web->get('/admin/kafka/consumers');
        $web->post('/admin/kafka/consumers/platform-default/pause', ['paused' => '1']);
        self::assertTrue((bool) (new Query($db))->select('paused')->from('consumer_state')->where(['consumer_group' => 'platform-default'])->scalar());
        self::assertStringContainsString('Paused', $web->body());
        $web->post('/admin/kafka/consumers/platform-default/pause', ['paused' => '0']);
        self::assertFalse((bool) (new Query($db))->select('paused')->from('consumer_state')->where(['consumer_group' => 'platform-default'])->scalar());
        self::assertSame(404, $web->post('/admin/kafka/consumers/no-such-group/pause', ['paused' => '1'])->getStatusCode());
    }

    public function testDeadLetterViewIsRedactedAndReplayRequiresReauth(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $id = $this->deadLetter();
        $web->get('/admin/kafka/dead-letters?group=e2e-group');
        self::assertStringContainsString('#' . $id, $web->body());
        $web->get('/admin/kafka/dead-letters/' . $id);
        self::assertStringNotContainsString('Sup3rSecret!', $web->body());
        self::assertStringNotContainsString('abc.def.ghi', $web->body());
        self::assertStringNotContainsString('leaky-token', $web->body());

        self::expireReauth($user['id']);
        $web->post('/admin/kafka/dead-letters/' . $id . '/replay');
        self::assertStringStartsWith('/auth/confirm', $web->url(), 'replay without step-up goes to confirmation');
        $db = Fixtures::db();
        self::assertNull((new Query($db))->select('replayed_at')->from('kafka_dead_letters')->where(['id' => $id])->scalar() ?: null);

        AdminSession::reauth($web, $user);
        $web->get('/admin/kafka/dead-letters/' . $id);
        $web->post('/admin/kafka/dead-letters/' . $id . '/replay');
        self::assertNotNull((new Query($db))->select('replayed_at')->from('kafka_dead_letters')->where(['id' => $id])->scalar() ?: null, 'replayed to the real broker');
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'kafka.dlq.replayed', 'entity_id' => (string) $id, 'result' => 'success'])->exists());

        // outbox re-queue (same step-up window)
        $ob = $this->failedOutbox();
        $web->get('/admin/kafka/outbox?status=failed');
        self::assertStringContainsString('e2e.happened', $web->body());
        $web->post('/admin/kafka/outbox/' . $ob . '/requeue');
        self::assertContains((new Query($db))->select('status')->from('outbox')->where(['id' => $ob])->scalar(), ['pending', 'published']);
    }

    public function testAuditorIsReadOnly(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $id = $this->deadLetter();
        self::assertSame(200, $web->get('/admin/kafka')->getStatusCode());
        self::assertSame(200, $web->get('/admin/kafka/dead-letters/' . $id)->getStatusCode());
        self::assertStringNotContainsString('/replay"', $web->body());
        self::assertSame(403, $web->post('/admin/kafka/dead-letters/' . $id . '/replay')->getStatusCode());
        self::assertSame(403, $web->post('/admin/kafka/consumers/platform-default/pause', ['paused' => '1'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/kafka/topics/new', ['name' => 'e2e.nope', 'partitions' => '1', 'replication_factor' => '1', 'retention_hours' => '1', 'cleanup_policy' => 'delete'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/kafka/outbox/requeue')->getStatusCode());
        self::assertSame(403, $web->get('/admin/kafka/topics/new')->getStatusCode());
    }
}
