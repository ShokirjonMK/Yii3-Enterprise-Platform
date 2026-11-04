<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RdKafka\KafkaConsumer;
use RdKafka\Producer;
use RdKafka\TopicPartition;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Cluster inspection & topic provisioning.
 *
 * Topic creation: php-rdkafka has no stable AdminClient API across versions, so topics are provisioned
 * by the `kafka:provision` command using Kafka's own CLI in CI/ops images, or — in development — with
 * `allow.auto.create` disabled and the broker-side kafka-topics.sh. The platform keeps the desired
 * state in the `kafka_topics` registry and reports drift (missing topics, partition mismatch).
 */
final class KafkaAdmin
{
    public function __construct(
        private readonly KafkaConfig $config,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return array{brokers:list<array{id:int,host:string}>, topics:array<string, array{partitions:int}>, controller:?int}
     */
    public function metadata(): array
    {
        if (!$this->config->enabled) {
            throw new RuntimeException('Kafka is not configured (KAFKA_ENABLED=false).');
        }
        $producer = new Producer($this->config->producerConf());
        $meta = $producer->getMetadata(true, null, 5000);
        $brokers = [];
        foreach ($meta->getBrokers() as $b) {
            $brokers[] = ['id' => $b->getId(), 'host' => $b->getHost() . ':' . $b->getPort()];
        }
        $topics = [];
        foreach ($meta->getTopics() as $t) {
            if (str_starts_with($t->getTopic(), '__')) {
                continue;
            }
            $topics[$t->getTopic()] = ['partitions' => count(iterator_to_array($t->getPartitions()))];
        }
        ksort($topics);
        return ['brokers' => $brokers, 'topics' => $topics, 'controller' => null];
    }

    /**
     * Consumer group lag per partition (committed offset vs high watermark).
     *
     * @param list<string> $topics
     * @return list<array{topic:string, partition:int, committed:int, high:int, lag:int}>
     */
    public function lag(string $group, array $topics): array
    {
        $meta = $this->metadata();
        $consumer = new KafkaConsumer($this->config->consumerConf($group));
        $partitions = [];
        foreach ($topics as $topic) {
            $name = $this->config->topic($topic);
            for ($p = 0; $p < ($meta['topics'][$name]['partitions'] ?? 0); $p++) {
                $partitions[] = new TopicPartition($name, $p);
            }
        }
        if ($partitions === []) {
            return [];
        }
        $committed = $consumer->getCommittedOffsets($partitions, 5000);
        $out = [];
        foreach ($committed as $tp) {
            $low = $high = 0;
            $consumer->queryWatermarkOffsets($tp->getTopic(), $tp->getPartition(), $low, $high, 5000);
            $off = $tp->getOffset();
            $out[] = [
                'topic' => $tp->getTopic(),
                'partition' => $tp->getPartition(),
                'committed' => $off < 0 ? -1 : $off,
                'high' => $high,
                'lag' => $off < 0 ? max(0, $high - $low) : max(0, $high - $off),
            ];
        }
        $consumer->close();
        return $out;
    }

    /** @return list<array<string,mixed>> registry rows with live state */
    public function topicDrift(): array
    {
        $registry = (new Query($this->db))->from('kafka_topics')->orderBy('name')->all();
        try {
            $live = $this->metadata()['topics'];
        } catch (Throwable) {
            $live = null;
        }
        foreach ($registry as &$r) {
            $name = $this->config->topic((string) $r['name']);
            $r['exists'] = $live === null ? null : isset($live[$name]);
            $r['live_partitions'] = $live[$name]['partitions'] ?? null;
            $r['drift'] = $live !== null && (!isset($live[$name]) || $live[$name]['partitions'] < (int) $r['partitions']);
        }
        return $registry;
    }
}
