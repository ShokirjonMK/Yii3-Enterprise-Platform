<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConfigFactory;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Messaging\Kafka\KafkaHandlerDiscovery;
use App\Platform\Messaging\Kafka\KafkaMessage;
use App\Platform\Messaging\Kafka\KafkaMessageHandlerInterface;
use App\Platform\Messaging\Kafka\KafkaProducer;
use App\Platform\Messaging\Kafka\KafkaPublisher;
use App\Platform\Messaging\Kafka\PermanentMessageFailure;
use App\Platform\Notifications\Kafka\InboundNotificationHandler;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/**
 * #[KafkaChannel] handlers (adopted from muxtorov98/yii2-kafka): discovery, settings-driven configuration,
 * and a real broker round trip with plain JSON messages — immediate retry with backoff, inbox
 * de-duplication by event_id, permanent failure → dead letter.
 *
 * Author: ShokirjonMK
 */
final class KafkaHandlersTest extends IntegrationTestCase
{
    public function testAttributeHandlersAreDiscoveredAndMergedIntoGroups(): void
    {
        $d = $this->get(KafkaHandlerDiscovery::class);
        $found = array_values(array_filter($d->handlers(), static fn($h) => $h['class'] === InboundNotificationHandler::class));
        self::assertCount(1, $found);
        self::assertSame('platform.notifications.inbound', $found[0]['topic']);
        self::assertSame('notifications-inbound', $found[0]['group']);
        self::assertSame('raw', $found[0]['mode']);

        $groups = $this->get(KafkaConsumerRunner::class)->groups();
        self::assertSame([InboundNotificationHandler::class], $groups['notifications-inbound']['raw']['platform.notifications.inbound']);
        self::assertContains('platform.notifications.inbound', $groups['notifications-inbound']['topics']);
        self::assertArrayHasKey('projects-audit', $groups, 'config-file groups are kept');
    }

    public function testConfigComesFromSettingsWithDefaults(): void
    {
        $cfg = $this->get(KafkaConfigFactory::class)->fresh();
        self::assertSame((string) (getenv('KAFKA_BROKERS') ?: 'kafka:9092'), $cfg->brokers, 'env overrides the setting');
        self::assertSame('all', $cfg->producerAcks);
        self::assertSame(3, $cfg->maxDeliveries);
        self::assertSame('retry_dlq', $cfg->onFailure);
        self::assertSame($cfg->fingerprint(), $this->get(KafkaConfigFactory::class)->fresh()->fingerprint(), 'stable fingerprint');
        self::assertNotSame($cfg->fingerprint(), (new KafkaConfig(true, 'other:9092', 'x', ''))->fingerprint());
        self::assertArrayNotHasKey('password', array_filter($cfg->describe(), static fn($v) => $v === 'secret'));
    }

    public function testInboundNotificationHandler(): void
    {
        $user = Fixtures::user(['user']);
        $meta = new KafkaMessage('m1', 'platform.notifications.inbound', 0, 0, null, ['source' => 'erp'], 'notifications-inbound', 1);
        $this->get(InboundNotificationHandler::class)->handle(['user' => $user['username'], 'subject' => 'Invoice paid', 'message' => 'Invoice #42 was paid'], $meta);
        $row = (new Query($this->db))->from('notifications')->where(['user_id' => $user['id'], 'channel' => 'inapp'])->orderBy(['created_at' => SORT_DESC])->one();
        self::assertNotNull($row);
        self::assertSame('Invoice paid', $row['subject']);
        self::assertSame('Invoice #42 was paid', $row['body']);

        $this->expectException(PermanentMessageFailure::class);
        $this->get(InboundNotificationHandler::class)->handle(['user' => 'nobody-' . uniqid(), 'message' => 'x'], $meta);
    }

    public function testRawJsonRoundTripWithRetryDedupAndDeadLetter(): void
    {
        $base = $this->get(KafkaConfig::class);
        if (!$base->enabled) {
            self::markTestSkipped('Kafka disabled');
        }
        $run = 'run-' . bin2hex(random_bytes(4));
        FlakySelftestHandler::$run = $run;
        FlakySelftestHandler::$handled = [];
        FlakySelftestHandler::$calls = 0;

        $publisher = $this->get(KafkaPublisher::class);
        $publisher->send('platform.selftest', ['run' => $run, 'n' => 1, 'fail_once' => true]);
        $publisher->send('platform.selftest', ['run' => $run, 'n' => 2], headers: ['event_id' => "dup-$run"]);
        $publisher->send('platform.selftest', ['run' => $run, 'n' => 2], headers: ['event_id' => "dup-$run"]); // redelivery
        $publisher->send('platform.selftest', ['run' => $run, 'n' => 3, 'permanent' => true]);
        $ids = $publisher->batch('platform.selftest', [['run' => $run, 'n' => 4], ['run' => $run, 'n' => 5]]);
        self::assertCount(2, $ids);

        $cfg = new KafkaConfig(true, $base->brokers, 'test', $base->topicPrefix, retryAttempts: 2, retryBackoffMs: 10, maxDeliveries: 3, onFailure: 'retry_dlq');
        $group = 'test-selftest-' . uniqid();
        $runner = new KafkaConsumerRunner(
            $cfg,
            new KafkaProducer($cfg),
            $this->db,
            $this->c,
            $this->get(\Psr\Log\LoggerInterface::class),
            $this->get(\App\Shared\Infrastructure\Context\RequestContext::class),
            $this->get(\App\Platform\Monitoring\Metrics::class),
            [$group => ['topics' => ['platform.selftest'], 'handlers' => [], 'raw' => ['platform.selftest' => [FlakySelftestHandler::class]]]],
        );
        $runner->run($group, null, 10);

        $mine = FlakySelftestHandler::$handled;
        sort($mine);
        self::assertSame([1, 2, 4, 5], $mine, 'retried once, de-duplicated, permanent failure not applied');
        $dl = (new Query($this->db))->from('kafka_dead_letters')->where(['consumer_group' => $group])->all();
        self::assertCount(1, $dl, 'permanent failure → dead letter without retries');
        self::assertStringContainsString('"n":3', (string) $dl[0]['payload']);
        self::assertSame(1, (int) $dl[0]['attempts']);
        self::assertTrue((new Query($this->db))->from('inbox')->where(['consumer_group' => $group, 'message_id' => "dup-$run"])->exists());
    }
}

final class FlakySelftestHandler implements KafkaMessageHandlerInterface
{
    public static string $run = '';
    /** @var list<int> */
    public static array $handled = [];
    public static int $calls = 0;
    /** @var array<int,bool> */
    private static array $failed = [];

    public function handle(array $payload, KafkaMessage $message): void
    {
        if (($payload['run'] ?? null) !== self::$run) {
            return; // messages of earlier test runs on the same topic
        }
        self::$calls++;
        $n = (int) $payload['n'];
        if (!empty($payload['permanent'])) {
            throw new PermanentMessageFailure('invalid test message');
        }
        if (!empty($payload['fail_once']) && !isset(self::$failed[$n])) {
            self::$failed[$n] = true;
            throw new \RuntimeException('temporary test failure');
        }
        self::$handled[] = $n;
    }
}
