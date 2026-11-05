<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RuntimeException;

/**
 * Creates topics through Kafka's binary protocol (CreateTopics v2, API key 19).
 *
 * Why custom code: php-rdkafka 6 has no AdminClient (RdKafka\Admin is not part of the extension), and the
 * application image has no Kafka CLI. CreateTopics v2 is supported by Kafka 2.0 … 4.x; in KRaft mode any
 * broker forwards the request to the active controller. Supported transports: PLAINTEXT, SSL, SASL_PLAINTEXT /
 * SASL_SSL with mechanism PLAIN. Other SASL mechanisms (SCRAM, OAUTHBEARER) → unsupported(): use the rendered
 * script (`./yii kafka:provision`) or the Kubernetes Topic Operator instead.
 *
 * Author: ShokirjonMK
 */
final class KafkaTopicCreator
{
    public const TOPIC_ALREADY_EXISTS = 36;

    private const ERRORS = [
        7 => 'REQUEST_TIMED_OUT', 29 => 'TOPIC_AUTHORIZATION_FAILED', 31 => 'CLUSTER_AUTHORIZATION_FAILED',
        36 => 'TOPIC_ALREADY_EXISTS', 37 => 'INVALID_PARTITIONS', 38 => 'INVALID_REPLICATION_FACTOR',
        39 => 'INVALID_REPLICA_ASSIGNMENT', 40 => 'INVALID_CONFIG', 41 => 'NOT_CONTROLLER', 17 => 'INVALID_TOPIC_EXCEPTION',
        42 => 'INVALID_REQUEST', 58 => 'SASL_AUTHENTICATION_FAILED', 33 => 'UNSUPPORTED_SASL_MECHANISM',
    ];

    private int $correlation = 0;

    public function __construct(private readonly KafkaConfig $config) {}

    /** null when topics can be created from PHP, otherwise the reason. */
    public function unsupported(): ?string
    {
        if (!$this->config->enabled) {
            return 'Kafka is not configured (KAFKA_ENABLED=false).';
        }
        $protocol = strtoupper($this->config->securityProtocol);
        if (!in_array($protocol, ['PLAINTEXT', 'SSL', 'SASL_PLAINTEXT', 'SASL_SSL'], true)) {
            return "Unknown security protocol $protocol.";
        }
        if (str_starts_with($protocol, 'SASL_') && strtoupper((string) $this->config->saslMechanism) !== 'PLAIN') {
            return 'SASL mechanism ' . ($this->config->saslMechanism ?: '(none)') . ' is not supported by the built-in topic creator (PLAIN only).';
        }
        return null;
    }

    public static function errorName(int $code): string
    {
        return self::ERRORS[$code] ?? 'ERROR_' . $code;
    }

    /**
     * @param list<array{name:string, partitions:int, replication_factor:int, configs?:array<string,string>}> $topics full topic names (prefix applied)
     * @return array<string, array{code:int, error:?string, message:?string}> per topic; code 0 = created, 36 = already existed
     */
    public function create(array $topics, int $timeoutMs = 15000): array
    {
        if (($reason = $this->unsupported()) !== null) {
            throw new RuntimeException($reason);
        }
        if ($topics === []) {
            return [];
        }
        $socket = $this->connect();
        try {
            if (str_starts_with(strtoupper($this->config->securityProtocol), 'SASL_')) {
                $this->authenticatePlain($socket);
            }
            $body = pack('N', count($topics));
            foreach ($topics as $t) {
                $configs = $t['configs'] ?? [];
                $body .= self::str($t['name']) . pack('N', $t['partitions']) . pack('n', $t['replication_factor'])
                    . pack('N', 0) // replica assignment: none
                    . pack('N', count($configs));
                foreach ($configs as $k => $v) {
                    $body .= self::str((string) $k) . self::str((string) $v);
                }
            }
            $body .= pack('N', $timeoutMs) . "\x00"; // timeout, validate_only = false
            $r = new KafkaWireReader($this->request($socket, 19, 2, $body, $timeoutMs + 5000));
            $r->int32(); // throttle_time_ms
            $out = [];
            for ($i = 0, $n = $r->int32(); $i < $n; $i++) {
                $name = (string) $r->string();
                $code = $r->int16();
                $message = $r->string();
                $out[$name] = ['code' => $code, 'error' => $code === 0 ? null : self::errorName($code), 'message' => $message];
            }
            return $out;
        } finally {
            fclose($socket);
        }
    }

    /**
     * DeleteTopics v1 (API key 20) — used by tests and clean-ups; the admin UI never deletes topics.
     *
     * @param list<string> $names full topic names
     * @return array<string, int> error code per topic (0 = deleted)
     */
    public function delete(array $names, int $timeoutMs = 15000): array
    {
        if (($reason = $this->unsupported()) !== null) {
            throw new RuntimeException($reason);
        }
        $socket = $this->connect();
        try {
            if (str_starts_with(strtoupper($this->config->securityProtocol), 'SASL_')) {
                $this->authenticatePlain($socket);
            }
            $body = pack('N', count($names));
            foreach ($names as $n) {
                $body .= self::str($n);
            }
            $r = new KafkaWireReader($this->request($socket, 20, 1, $body . pack('N', $timeoutMs), $timeoutMs + 5000));
            $r->int32(); // throttle_time_ms
            $out = [];
            for ($i = 0, $n = $r->int32(); $i < $n; $i++) {
                $name = (string) $r->string();
                $out[$name] = $r->int16();
            }
            return $out;
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $errors = [];
        foreach (array_filter(array_map('trim', explode(',', $this->config->brokers))) as $broker) {
            $tls = str_ends_with(strtoupper($this->config->securityProtocol), 'SSL');
            $ctx = stream_context_create(['ssl' => array_filter([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $this->config->sslCaLocation ?: null,
            ], static fn($v) => $v !== null)]);
            $socket = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . $broker, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (is_resource($socket)) {
                return $socket;
            }
            $errors[] = "$broker: $errstr";
        }
        throw new RuntimeException('Cannot connect to Kafka: ' . implode('; ', $errors));
    }

    /** @param resource $socket */
    private function authenticatePlain($socket): void
    {
        $r = new KafkaWireReader($this->request($socket, 17, 1, self::str('PLAIN'), 10000)); // SaslHandshake v1
        $code = $r->int16();
        if ($code !== 0) {
            throw new RuntimeException('SASL handshake failed: ' . self::errorName($code));
        }
        $token = "\x00" . (string) $this->config->saslUsername . "\x00" . (string) $this->config->saslPassword;
        $r = new KafkaWireReader($this->request($socket, 36, 0, pack('N', strlen($token)) . $token, 10000)); // SaslAuthenticate v0
        $code = $r->int16();
        if ($code !== 0) {
            throw new RuntimeException('SASL authentication failed: ' . ($r->string() ?? self::errorName($code)));
        }
    }

    /** @param resource $socket */
    private function request($socket, int $apiKey, int $version, string $body, int $timeoutMs): string
    {
        $id = ++$this->correlation;
        $header = pack('n', $apiKey) . pack('n', $version) . pack('N', $id) . self::str($this->config->clientId ?: 'yii3-platform');
        $payload = $header . $body;
        stream_set_timeout($socket, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);
        if (@fwrite($socket, pack('N', strlen($payload)) . $payload) === false) {
            throw new RuntimeException('Kafka connection closed while sending.');
        }
        $size = unpack('N', $this->read($socket, 4))[1];
        $response = $this->read($socket, $size);
        if (unpack('N', substr($response, 0, 4))[1] !== $id) {
            throw new RuntimeException('Kafka returned an unexpected correlation id.');
        }
        return substr($response, 4);
    }

    /** @param resource $socket */
    private function read($socket, int $length): string
    {
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = fread($socket, $length - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                throw new RuntimeException($meta['timed_out'] ? 'Kafka did not answer in time.' : 'Kafka closed the connection.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }
}
