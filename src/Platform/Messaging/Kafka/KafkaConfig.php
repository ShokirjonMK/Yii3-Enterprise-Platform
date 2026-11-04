<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RdKafka\Conf;

/**
 * librdkafka configuration shared by producer, consumers and admin tooling — built by {@see KafkaConfigFactory}
 * from the typed settings `kafka.*` (Admin → 11 Kafka → Settings; environment variables win).
 *
 * Producer: idempotent (enable.idempotence) with acks=all by default, so together with the transactional
 * outbox no event is lost or duplicated on the broker side. Delivery confirmation is retried
 * {@see $producerFlushRetries} times (idea adopted from muxtorov98/yii2-kafka, MIT).
 * Consumer: manual commits, cooperative-sticky assignment; failed handlers are retried in-process
 * ({@see $retryAttempts} with {@see $retryBackoffMs}) and then according to {@see $onFailure}.
 *
 * Author: ShokirjonMK
 */
final readonly class KafkaConfig
{
    public const ON_FAILURE = ['retry_dlq', 'dlq', 'skip'];

    public function __construct(
        public bool $enabled,
        public string $brokers,
        public string $clientId,
        public string $topicPrefix,
        public string $securityProtocol = 'PLAINTEXT',
        public ?string $saslMechanism = null,
        public ?string $saslUsername = null,
        public ?string $saslPassword = null,
        public ?string $sslCaLocation = null,
        // producer
        public string $producerAcks = 'all',
        public string $producerCompression = 'lz4',
        public int $producerLingerMs = 5,
        public int $producerFlushTimeoutMs = 15000,
        public int $producerFlushRetries = 3,
        // consumer
        public string $consumerAutoOffsetReset = 'earliest',
        public int $consumerPollTimeoutMs = 1000,
        public int $consumerSessionTimeoutMs = 30000,
        public int $consumerMaxPollIntervalMs = 300000,
        // failure policy
        public int $retryAttempts = 1,
        public int $retryBackoffMs = 500,
        public int $maxDeliveries = 3,
        public string $onFailure = 'retry_dlq',
    ) {}

    /** Bootstrap fallback (environment only) — used when settings are not available yet (install, migrations). */
    public static function fromParams(array $p): self
    {
        return new self(
            (bool) $p['enabled'],
            (string) $p['brokers'],
            (string) $p['clientId'],
            (string) $p['topicPrefix'],
            (string) $p['securityProtocol'],
            $p['saslMechanism'] ?: null,
            $p['saslUsername'] ?: null,
            $p['saslPassword'] ?: null,
            $p['sslCaLocation'] ?: null,
        );
    }

    public function topic(string $name): string
    {
        return str_starts_with($name, $this->topicPrefix) ? $name : $this->topicPrefix . $name;
    }

    /** Stable hash of everything that requires reconnecting (long-running processes restart when it changes). */
    public function fingerprint(): string
    {
        return hash('sha256', serialize(get_object_vars($this)));
    }

    /** @return array<string, string|int|bool|null> safe for display (no password) */
    public function describe(): array
    {
        $v = get_object_vars($this);
        $v['saslPassword'] = $this->saslPassword === null ? null : '••••••';
        return $v;
    }

    public function producerConf(): Conf
    {
        $conf = $this->base();
        $idempotent = $this->producerAcks === 'all';
        $conf->set('enable.idempotence', $idempotent ? 'true' : 'false');
        $conf->set('acks', $this->producerAcks);
        $conf->set('compression.type', $this->producerCompression);
        $conf->set('linger.ms', (string) $this->producerLingerMs);
        $conf->set('message.timeout.ms', (string) max(30000, $this->producerFlushTimeoutMs));
        $conf->set('max.in.flight.requests.per.connection', '5');
        return $conf;
    }

    public function consumerConf(string $groupId): Conf
    {
        $conf = $this->base();
        $conf->set('group.id', $groupId);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', $this->consumerAutoOffsetReset);
        $conf->set('enable.partition.eof', 'false');
        $conf->set('session.timeout.ms', (string) $this->consumerSessionTimeoutMs);
        $conf->set('max.poll.interval.ms', (string) max($this->consumerMaxPollIntervalMs, $this->consumerSessionTimeoutMs));
        $conf->set('partition.assignment.strategy', 'cooperative-sticky');
        return $conf;
    }

    private function base(): Conf
    {
        $conf = new Conf();
        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('client.id', $this->clientId);
        $conf->set('socket.timeout.ms', '10000');
        $conf->set('security.protocol', $this->securityProtocol);
        if ($this->saslMechanism !== null && $this->saslMechanism !== '') {
            $conf->set('sasl.mechanisms', $this->saslMechanism);
            $conf->set('sasl.username', (string) $this->saslUsername);
            $conf->set('sasl.password', (string) $this->saslPassword);
        }
        if ($this->sslCaLocation !== null && $this->sslCaLocation !== '') {
            $conf->set('ssl.ca.location', $this->sslCaLocation);
        }
        $conf->set('log_level', (string) LOG_WARNING);
        return $conf;
    }
}
