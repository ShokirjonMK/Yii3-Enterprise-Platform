<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RdKafka\Conf;

/**
 * librdkafka configuration shared by producer, consumers and admin tooling.
 * Producer is idempotent (enable.idempotence) with acks=all so that, combined with the
 * transactional outbox, no event is lost or duplicated on the broker side.
 */
final readonly class KafkaConfig
{
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
    ) {}

    public static function fromParams(array $p): self
    {
        return new self(
            (bool) $p['enabled'],
            (string) $p['brokers'],
            (string) $p['clientId'],
            (string) $p['topicPrefix'],
            (string) $p['securityProtocol'],
            $p['saslMechanism'],
            $p['saslUsername'],
            $p['saslPassword'],
            $p['sslCaLocation'],
        );
    }

    public function topic(string $name): string
    {
        return str_starts_with($name, $this->topicPrefix) ? $name : $this->topicPrefix . $name;
    }

    public function producerConf(): Conf
    {
        $conf = $this->base();
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('compression.type', 'lz4');
        $conf->set('linger.ms', '5');
        $conf->set('message.timeout.ms', '30000');
        $conf->set('max.in.flight.requests.per.connection', '5');
        return $conf;
    }

    public function consumerConf(string $groupId): Conf
    {
        $conf = $this->base();
        $conf->set('group.id', $groupId);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.partition.eof', 'false');
        $conf->set('session.timeout.ms', '30000');
        $conf->set('max.poll.interval.ms', '300000');
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
        if ($this->saslMechanism !== null) {
            $conf->set('sasl.mechanisms', $this->saslMechanism);
            $conf->set('sasl.username', (string) $this->saslUsername);
            $conf->set('sasl.password', (string) $this->saslPassword);
        }
        if ($this->sslCaLocation !== null) {
            $conf->set('ssl.ca.location', $this->sslCaLocation);
        }
        $conf->set('log_level', (string) LOG_WARNING);
        return $conf;
    }
}
