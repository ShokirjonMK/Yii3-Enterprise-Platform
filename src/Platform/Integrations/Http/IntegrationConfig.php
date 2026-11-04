<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Http;

use App\Shared\Infrastructure\Database\Sql;
use SensitiveParameter;

/**
 * Immutable outbound-HTTP profile: either an `integrations` row ({@see fromRow()}) or an ad-hoc provider profile
 * ({@see adhoc()}) used by notification channels (Telegram, SMS) and alerting.
 */
final readonly class IntegrationConfig
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $authConfig api_key_header | username | hmac_header/hmac_timestamp_header
     */
    public function __construct(
        public string $code,
        public ?string $id = null,
        public string $baseUrl = '',
        public bool $enabled = true,
        public string $authType = 'none',
        #[SensitiveParameter] public ?string $secret = null,
        public array $authConfig = [],
        public array $headers = [],
        public int $timeoutMs = 10000,
        public int $connectTimeoutMs = 3000,
        public int $maxRetries = 3,
        public int $backoffMs = 200,
        public int $circuitThreshold = 5,
        public int $circuitCooldownS = 30,
        public int $rateLimitPerMinute = 0,
        public ?string $healthPath = null,
        public bool $allowPrivateNetwork = false,
        public string $status = 'unconfigured',
    ) {}

    /** @param array<string,mixed> $row integrations row; $secret already decrypted */
    public static function fromRow(array $row, #[SensitiveParameter] ?string $secret): self
    {
        return new self(
            code: (string) $row['code'],
            id: (string) $row['id'],
            baseUrl: (string) ($row['base_url'] ?? ''),
            enabled: Sql::bool($row['enabled']),
            authType: (string) $row['auth_type'],
            secret: $secret,
            authConfig: (array) Sql::json($row['auth_config'] ?? null),
            headers: array_map('strval', (array) Sql::json($row['headers'] ?? null)),
            timeoutMs: (int) $row['timeout_ms'],
            connectTimeoutMs: (int) $row['connect_timeout_ms'],
            maxRetries: (int) $row['max_retries'],
            backoffMs: (int) $row['backoff_ms'],
            circuitThreshold: (int) $row['circuit_threshold'],
            circuitCooldownS: (int) $row['circuit_cooldown_s'],
            rateLimitPerMinute: (int) $row['rate_limit_per_minute'],
            healthPath: $row['health_path'] !== null && $row['health_path'] !== '' ? (string) $row['health_path'] : null,
            allowPrivateNetwork: Sql::bool($row['allow_private_network']),
            status: (string) $row['status'],
        );
    }

    /** Provider profile that is not stored in `integrations` (logs are written with integration_id = NULL). */
    public static function adhoc(string $code, string $baseUrl, int $timeoutMs = 10000, int $maxRetries = 2, int $connectTimeoutMs = 3000): self
    {
        return new self(code: $code, baseUrl: $baseUrl, timeoutMs: $timeoutMs, connectTimeoutMs: $connectTimeoutMs, maxRetries: $maxRetries, status: 'healthy');
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $props = get_object_vars($this);
        $props['baseUrl'] = $baseUrl;
        return new self(...$props);
    }

    public function withRetries(int $maxRetries, int $backoffMs): self
    {
        $props = get_object_vars($this);
        $props['maxRetries'] = $maxRetries;
        $props['backoffMs'] = $backoffMs;
        return new self(...$props);
    }
}
