<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections\ClickHouse;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use SensitiveParameter;

/**
 * Minimal ClickHouse client over the official HTTP interface (port 8123 / 8443) — no PHP extension needed.
 *
 *   $ch->select('SELECT event, count() c FROM events WHERE day >= {from:Date} GROUP BY event', ['from' => '2026-11-01']);
 *   $ch->insert('events', [['day' => '2026-11-05', 'event' => 'login', 'user_id' => $id]]);
 *   $ch->execute('CREATE TABLE IF NOT EXISTS events (...) ENGINE = MergeTree ORDER BY day');
 *
 * Parameters use ClickHouse's server-side binding ({name:Type} + param_name), never string interpolation.
 * Credentials travel in X-ClickHouse-User / X-ClickHouse-Key headers, not in the URL.
 *
 * Author: ShokirjonMK
 */
final class ClickHouseClient
{
    private readonly ClientInterface $http;

    /** @param array<string, string|int> $settings default ClickHouse settings sent with every query */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $database,
        private readonly string $username = 'default',
        #[SensitiveParameter]
        private readonly string $password = '',
        float $timeout = 30.0,
        private readonly array $settings = [],
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => $timeout, 'connect_timeout' => min(5.0, $timeout), 'http_errors' => false]);
    }

    public function ping(): bool
    {
        try {
            $r = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/ping');
            return $r->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * @param array<string, scalar|null|array> $params
     * @param array<string, string|int> $settings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = [], array $settings = []): array
    {
        return $this->selectWithMeta($sql, $params, $settings)['data'];
    }

    /**
     * @return array{data: list<array<string,mixed>>, meta: list<array{name:string,type:string}>, rows: int, statistics: array<string,mixed>}
     */
    public function selectWithMeta(string $sql, array $params = [], array $settings = []): array
    {
        $body = $this->request(rtrim($sql, " \t\n\r;") . "\nFORMAT JSON", $params, $settings);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new ClickHouseException('ClickHouse returned a non-JSON response.');
        }
        return [
            'data' => $json['data'] ?? [],
            'meta' => $json['meta'] ?? [],
            'rows' => (int) ($json['rows'] ?? count($json['data'] ?? [])),
            'statistics' => $json['statistics'] ?? [],
        ];
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->select($sql, $params)[0] ?? null;
        return $row === null ? null : reset($row);
    }

    /** DDL / mutations / INSERT … SELECT. */
    public function execute(string $sql, array $params = [], array $settings = []): void
    {
        $this->request($sql, $params, $settings);
    }

    /**
     * Batch insert as JSONEachRow (one HTTP request).
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)) {
            throw new ClickHouseException("Invalid table name \"$table\".");
        }
        $payload = '';
        foreach ($rows as $row) {
            $payload .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        }
        $this->send(['query' => "INSERT INTO $table FORMAT JSONEachRow"], $payload);
        return count($rows);
    }

    public function version(): string
    {
        return (string) $this->scalar('SELECT version()');
    }

    public function database(): string
    {
        return $this->database;
    }

    private function request(string $sql, array $params, array $settings): string
    {
        $query = [];
        foreach ($params as $k => $v) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k)) {
                throw new ClickHouseException("Invalid parameter name \"$k\".");
            }
            $query['param_' . $k] = match (true) {
                is_bool($v) => $v ? '1' : '0',
                $v === null => '\\N',
                is_array($v) => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => (string) $v,
            };
        }
        return $this->send($query + $settings, $sql);
    }

    private function send(array $query, string $body): string
    {
        $query += $this->settings + ['database' => $this->database];
        try {
            $r = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/', [
                'query' => $query,
                'body' => $body,
                'headers' => ['X-ClickHouse-User' => $this->username, 'X-ClickHouse-Key' => $this->password, 'Content-Type' => 'text/plain; charset=utf-8'],
            ]);
        } catch (RequestException|GuzzleException $e) {
            throw new ClickHouseException('ClickHouse is unreachable: ' . $e->getMessage(), 0, $e instanceof RuntimeException ? $e : null);
        }
        $text = (string) $r->getBody();
        if ($r->getStatusCode() !== 200) {
            throw new ClickHouseException('ClickHouse error (HTTP ' . $r->getStatusCode() . '): ' . mb_substr(trim($text), 0, 1000));
        }
        return $text;
    }
}
