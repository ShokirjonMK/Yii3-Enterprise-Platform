<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RdKafka\Producer;
use RuntimeException;

/**
 * Idempotent Kafka producer (acks=all). {@see publishBatch()} waits for broker acknowledgement of
 * every message — the outbox row is marked published only after a positive delivery report.
 */
final class KafkaProducer
{
    private ?Producer $producer = null;
    /** @var array<string, bool|string> message key → true | error string */
    private array $reports = [];

    public function __construct(private readonly KafkaConfig $config) {}

    /**
     * @param list<array{id:string, topic:string, key:string, value:string, headers:array<string,string>}> $messages
     * @return array<string, true|string> id → true (acked) | error message
     */
    public function publishBatch(array $messages, int $timeoutMs = 15000): array
    {
        if (!$this->config->enabled) {
            throw new RuntimeException('Kafka is disabled (KAFKA_ENABLED=false).');
        }
        $producer = $this->producer();
        $this->reports = [];
        $topics = [];
        foreach ($messages as $m) {
            $topic = $topics[$m['topic']] ??= $producer->newTopic($this->config->topic($m['topic']));
            $this->reports[$m['id']] = 'no delivery report';
            // opaque = outbox id, echoed back in the delivery report callback
            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $m['value'], $m['key'], $m['headers'], null, $m['id']);
            $producer->poll(0);
        }
        $deadline = microtime(true) + $timeoutMs / 1000;
        while ($producer->getOutQLen() > 0 && microtime(true) < $deadline) {
            $producer->poll(50);
        }
        $producer->flush(1000);
        return $this->reports;
    }

    public function publish(string $topic, string $key, string $value, array $headers = []): void
    {
        $id = bin2hex(random_bytes(8));
        $r = $this->publishBatch([['id' => $id, 'topic' => $topic, 'key' => $key, 'value' => $value, 'headers' => $headers]]);
        if (($r[$id] ?? null) !== true) {
            throw new RuntimeException('Kafka publish failed: ' . (is_string($r[$id] ?? null) ? $r[$id] : 'unknown'));
        }
    }

    private function producer(): Producer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }
        $conf = $this->config->producerConf();
        $conf->setDrMsgCb(function ($kafka, \RdKafka\Message $message): void {
            $id = (string) $message->opaque;
            $this->reports[$id] = $message->err === RD_KAFKA_RESP_ERR_NO_ERROR ? true : rd_kafka_err2str($message->err);
        });
        return $this->producer = new Producer($conf);
    }
}
