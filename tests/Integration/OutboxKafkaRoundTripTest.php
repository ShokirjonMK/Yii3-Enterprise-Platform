<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Messaging\EventEnvelope;
use App\Platform\Messaging\EventHandlerInterface;
use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Messaging\Outbox;
use App\Platform\Messaging\OutboxRelay;
use Yiisoft\Db\Query\Query;

/**
 * Real Kafka (KRaft) round trip: transactional outbox → relay → broker → consumer group with inbox
 * idempotency. Skipped when Kafka is not configured.
 */
final class OutboxKafkaRoundTripTest extends IntegrationTestCase
{
    public static array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->get(KafkaConfig::class)->enabled) {
            self::markTestSkipped('Kafka disabled');
        }
        self::$received = [];
        // messaging.kafka is OFF on fresh installs (unused features disabled); this test needs it on
        $this->db->createCommand()->update('feature_flags', ['enabled' => true, 'status' => 'enabled'], ['code' => 'messaging.kafka'])->execute();
        $this->get(\App\Platform\Settings\ConfigRevision::class)->bump();
    }

    public function testOutboxEventIsPublishedAndConsumedOnce(): void
    {
        $aggregate = 'agg-' . uniqid();
        $envelope = $this->db->transaction(fn() => $this->get(Outbox::class)->record('platform.users', 'test.ping', 'test', $aggregate, ['n' => 1]));

        /** @var OutboxRelay $relay */
        $relay = $this->get(OutboxRelay::class);
        $r = $relay->relayOnce(500);
        self::assertGreaterThanOrEqual(1, $r['published'], json_encode($r));
        self::assertSame('published', (new Query($this->db))->select('status')->from('outbox')->where(['id' => $envelope->id])->scalar());

        $runner = new KafkaConsumerRunner(
            $this->get(KafkaConfig::class),
            $this->get(\App\Platform\Messaging\Kafka\KafkaProducer::class),
            $this->db,
            $this->c,
            $this->get(\Psr\Log\LoggerInterface::class),
            $this->get(\App\Shared\Infrastructure\Context\RequestContext::class),
            $this->get(\App\Platform\Monitoring\Metrics::class),
            ['test-group-' . uniqid() => ['topics' => ['platform.users'], 'handlers' => ['test.ping' => RecordingHandler::class]]],
        );
        $group = array_key_first($runner->groups());
        $runner->run($group, null, 12);

        $mine = array_values(array_filter(self::$received, static fn(EventEnvelope $e) => $e->aggregateId === $aggregate));
        self::assertCount(1, $mine, 'event consumed exactly once');
        self::assertSame($envelope->id, $mine[0]->id);
        self::assertTrue((new Query($this->db))->from('inbox')->where(['consumer_group' => $group, 'message_id' => $envelope->id])->exists());

        // Idempotency: re-processing the same message id is a no-op
        $fake = new \RdKafka\Message();
        $fake->payload = $envelope->toJson();
        $fake->topic_name = 'platform.users';
        $fake->headers = $envelope->headers();
        $runner->process($group, ['test.ping' => RecordingHandler::class], $fake);
        self::assertCount(1, array_filter(self::$received, static fn(EventEnvelope $e) => $e->aggregateId === $aggregate));
    }

    public function testKafkaUnavailableKeepsEventsPending(): void
    {
        $cfg = new KafkaConfig(true, 'unreachable.invalid:9092', 'test', '');
        $relay = new OutboxRelay(
            $this->db,
            new \App\Platform\Messaging\Kafka\KafkaProducer($cfg),
            $this->get(\App\Platform\FeatureFlags\FeatureFlagService::class),
            $this->get(\App\Platform\Monitoring\Metrics::class),
            $this->get(\Psr\Log\LoggerInterface::class),
        );
        $this->db->createCommand("UPDATE outbox SET status = 'published' WHERE status = 'pending'")->execute();
        $env = $this->db->transaction(fn() => $this->get(Outbox::class)->record('platform.users', 'test.lost', 'test', 'x', []));
        $r = $relay->relayOnce(10);
        self::assertSame(0, $r['published']);
        $row = (new Query($this->db))->from('outbox')->where(['id' => $env->id])->one();
        self::assertSame('pending', $row['status'], 'never dropped');
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotEmpty($row['last_error']);
    }
}

final class RecordingHandler implements EventHandlerInterface
{
    public function handle(EventEnvelope $event): void
    {
        OutboxKafkaRoundTripTest::$received[] = $event;
    }
}
