<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Exception\NotConfiguredException;
use App\Platform\Integrations\Http\CircuitBreaker;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use App\Platform\Security\UrlGuard;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Security\Encrypter;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * External integrations: CRUD, enable/disable, encrypted credentials, connection test, status tracking and
 * circuit-breaker administration. Application code calls integrations through {@see call()}:
 *
 *   $response = $integrations->call('crm', 'GET', '/v1/customers', ['query' => ['page' => 1]]);
 */
final class IntegrationService
{
    public const AUTH_TYPES = ['none', 'bearer', 'basic', 'api_key', 'hmac'];
    public const STATUSES = ['unconfigured', 'healthy', 'degraded', 'failed', 'maintenance'];
    private const HEADER_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly HttpClient $http,
        private readonly CircuitBreaker $breaker,
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
    ) {}

    public function query(): Query
    {
        return (new Query($this->db))->from('integrations');
    }

    public function find(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->query()->where(['id' => $id])->one() : null;
        return $row ?? throw new NotFoundException('Integration not found.');
    }

    public function findByCode(string $code): array
    {
        return $this->query()->where(['code' => $code])->one() ?? throw new NotFoundException("Integration \"$code\" not found.");
    }

    public function config(string $code): IntegrationConfig
    {
        $row = $this->findByCode($code);
        return IntegrationConfig::fromRow($row, $this->secretOf($row));
    }

    /**
     * Calls an integration through the platform HTTP client (retries, breaker, SSRF guard, logging).
     *
     * @param array<string,mixed> $options
     * @throws IntegrationException
     */
    public function call(string $code, string $method, string $path, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->http->send($this->config($code), $method, $path, $options);
    }

    /** @param array<string,mixed> $input */
    public function create(string $actorId, array $input): string
    {
        $this->access->assert($actorId, 'integrations.manage');
        $data = $this->validate($input, null);
        if ($this->query()->where(['code' => $data['code']])->exists()) {
            throw ValidationException::single('code', 'This code is already used.');
        }
        $id = Uuid::v7();
        $this->db->transaction(function () use ($id, $data, $actorId): void {
            $this->db->createCommand()->insert('integrations', Sql::row(['id' => $id, 'enabled' => false, 'status' => 'unconfigured'] + $data))->execute();
            $this->audit->record('integration.created', 'integration', $id, null, $data, category: 'config', severity: 'notice', actorId: $actorId);
        });
        return $id;
    }

    /** @param array<string,mixed> $input */
    public function update(string $actorId, string $id, array $input, ?int $version = null): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $data = $this->validate($input + ['code' => $row['code']], $row);
        unset($data['code']); // the code is the stable identifier used by application code
        $this->db->transaction(function () use ($row, $data, $actorId, $version): void {
            $n = $this->db->createCommand()->update(
                'integrations',
                Sql::row($data) + ['version' => new Expression('version + 1')],
                ['id' => $row['id'], 'version' => $version ?? (int) $row['version']],
            )->execute();
            if ($n === 0) {
                throw new ConflictException('The integration was modified concurrently. Reload and try again.', 'stale_version');
            }
            $before = array_intersect_key($row, $data);
            $this->audit->record('integration.updated', 'integration', $row['id'], $before, $data, category: 'config', severity: 'notice', actorId: $actorId);
        });
    }

    public function setEnabled(string $actorId, string $id, bool $enabled): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        if ($enabled && (string) $row['base_url'] === '') {
            throw ValidationException::single('base_url', 'Set a base URL before enabling the integration.');
        }
        if ($enabled && $row['auth_type'] !== 'none' && $row['secret_enc'] === null) {
            throw ValidationException::single('secret', 'Set the credential before enabling the integration.');
        }
        $this->db->transaction(function () use ($row, $enabled, $actorId): void {
            $this->db->createCommand()->update('integrations', ['enabled' => $enabled, 'version' => new Expression('version + 1')], ['id' => $row['id']])->execute();
            $this->audit->record(
                $enabled ? 'integration.enabled' : 'integration.disabled',
                'integration',
                $row['id'],
                ['enabled' => Sql::bool($row['enabled'])],
                ['enabled' => $enabled],
                category: 'config',
                severity: 'notice',
                actorId: $actorId,
            );
        });
    }

    /**
     * Security-sensitive settings (route requires step-up re-authentication): credential and private-network access.
     * A null/empty secret keeps the stored one unless $clearSecret is true.
     */
    public function updateSecurity(string $actorId, string $id, #[SensitiveParameter] ?string $secret, bool $clearSecret, bool $allowPrivateNetwork): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $data = ['allow_private_network' => $allowPrivateNetwork];
        $secretChanged = false;
        if ($clearSecret) {
            $data['secret_enc'] = null;
            $secretChanged = true;
        } elseif ($secret !== null && $secret !== '') {
            if (strlen($secret) > 4096) {
                throw ValidationException::single('secret', 'The secret is too long.');
            }
            $data['secret_enc'] = $this->encrypter->encrypt($secret, 'integration');
            $secretChanged = true;
        }
        $this->db->transaction(function () use ($row, $data, $actorId, $secretChanged, $allowPrivateNetwork): void {
            $this->db->createCommand()->update('integrations', $data + ['version' => new Expression('version + 1')], ['id' => $row['id']])->execute();
            $this->audit->record(
                'integration.security_changed',
                'integration',
                $row['id'],
                ['allow_private_network' => Sql::bool($row['allow_private_network'])],
                ['allow_private_network' => $allowPrivateNetwork, 'secret' => $secretChanged ? 'changed' : 'unchanged'],
                category: 'security',
                severity: $allowPrivateNetwork ? 'warning' : 'notice',
                actorId: $actorId,
            );
        });
    }

    public function delete(string $actorId, string $id): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $this->db->transaction(function () use ($row, $actorId): void {
            $this->db->createCommand()->delete('integrations', ['id' => $row['id']])->execute();
            $this->audit->record('integration.deleted', 'integration', $row['id'], ['code' => $row['code'], 'name' => $row['name']], null, category: 'config', severity: 'warning', actorId: $actorId);
        });
        $this->breaker->reset((string) $row['code']);
    }

    /**
     * GET <base_url><health_path> through the full client stack; updates status/last_health_at/last_error.
     *
     * @return array{ok:bool, status:string, http_status:?int, duration_ms:int, error:?string, error_class:?string}
     */
    public function testConnection(string $actorId, string $id): array
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $cfg = IntegrationConfig::fromRow($row, $this->secretOf($row));
        // a test must also work while the integration is disabled; one attempt only, no retry storm
        $cfg = new IntegrationConfig(...array_replace(get_object_vars($cfg), ['enabled' => true, 'maxRetries' => 0]));
        $t0 = hrtime(true);
        $result = ['ok' => false, 'status' => 'failed', 'http_status' => null, 'duration_ms' => 0, 'error' => null, 'error_class' => null];
        try {
            $r = $this->http->send($cfg, 'GET', (string) ($row['health_path'] ?? ''));
            $result = ['ok' => true, 'status' => 'healthy', 'http_status' => $r->getStatusCode()] + $result;
        } catch (NotConfiguredException $e) {
            $result = ['status' => 'unconfigured', 'error' => $e->getMessage(), 'error_class' => $e->errorClass()] + $result;
        } catch (IntegrationException $e) {
            $result = ['status' => in_array($e->errorClass(), ['circuit_open', 'rate_limited_local'], true) ? 'degraded' : 'failed', 'http_status' => $e->statusCode(), 'error' => $e->getMessage(), 'error_class' => $e->errorClass()] + $result;
        }
        $result['duration_ms'] = (int) round((hrtime(true) - $t0) / 1e6);
        $this->db->transaction(function () use ($row, $result, $actorId): void {
            $this->db->createCommand()->update('integrations', [
                'status' => $result['status'], 'last_health_at' => new Expression('now()'), 'last_error' => $result['error'] === null ? null : mb_substr($result['error'], 0, 1000),
            ], ['id' => $row['id']])->execute();
            $this->audit->record(
                'integration.tested',
                'integration',
                $row['id'],
                null,
                null,
                ['status' => $result['status'], 'http_status' => $result['http_status'], 'error_class' => $result['error_class']],
                result: $result['ok'] ? 'success' : 'failure',
                category: 'config',
                actorId: $actorId,
            );
        });
        return $result;
    }

    /** @return array{state:string, failures:int, opened_at:?int, retry_in:int, available:bool} */
    public function breakerState(array $row): array
    {
        return $this->breaker->state((string) $row['code'], (int) $row['circuit_cooldown_s']);
    }

    public function resetBreaker(string $actorId, string $id): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $before = $this->breakerState($row);
        $this->breaker->reset((string) $row['code']);
        $this->audit->record('integration.circuit_reset', 'integration', $row['id'], ['state' => $before['state'], 'failures' => $before['failures']], ['state' => 'closed'], category: 'config', severity: 'notice', actorId: $actorId);
    }

    public function logsQuery(?string $integrationId = null): Query
    {
        $q = (new Query($this->db))->select(['l.*', 'i.code AS integration_code'])->from(['l' => 'integration_logs'])->leftJoin(['i' => 'integrations'], 'i.id = l.integration_id');
        if ($integrationId !== null) {
            $q->where(['l.integration_id' => $integrationId]);
        }
        return $q;
    }

    public function hasSecret(array $row): bool
    {
        return $row['secret_enc'] !== null && $row['secret_enc'] !== '';
    }

    private function secretOf(array $row): ?string
    {
        return $this->hasSecret($row) ? $this->encrypter->decrypt((string) $row['secret_enc'], 'integration') : null;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function validate(array $in, ?array $current): array
    {
        $e = [];
        $str = static fn(string $k, string $d = ''): string => trim((string) ($in[$k] ?? $d));
        $int = static function (string $k, int $min, int $max, int $default) use ($in, &$e): int {
            $raw = $in[$k] ?? null;
            if ($raw === null || $raw === '') {
                return $default;
            }
            $v = filter_var($raw, FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) {
                $e[$k][] = "Must be an integer between $min and $max.";
                return $default;
            }
            return $v;
        };
        $code = strtolower($str('code'));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) !== 1) {
            $e['code'][] = '2–64 characters: lowercase letters, digits, "-" and "_".';
        }
        $name = $str('name');
        if ($name === '' || mb_strlen($name) > 255) {
            $e['name'][] = 'Name is required (max 255 characters).';
        }
        $baseUrl = $str('base_url');
        if ($baseUrl !== '') {
            if (strlen($baseUrl) > 1024) {
                $e['base_url'][] = 'Maximum 1024 characters.';
            } elseif (($err = UrlGuard::syntaxError($baseUrl)) !== null) {
                $e['base_url'][] = $err;
            }
        }
        $authType = $str('auth_type', 'none');
        if (!in_array($authType, self::AUTH_TYPES, true)) {
            $e['auth_type'][] = 'Unknown authentication type.';
        }
        $authConfig = [];
        if ($authType === 'basic') {
            $authConfig['username'] = mb_substr($str('auth_username'), 0, 255);
            if ($authConfig['username'] === '') {
                $e['auth_username'][] = 'Username is required for basic authentication.';
            }
        } elseif ($authType === 'api_key') {
            $authConfig['api_key_header'] = $str('api_key_header', 'X-API-Key') ?: 'X-API-Key';
        } elseif ($authType === 'hmac') {
            $authConfig['hmac_header'] = $str('hmac_header', 'X-Signature') ?: 'X-Signature';
            $authConfig['hmac_timestamp_header'] = $str('hmac_timestamp_header', 'X-Timestamp') ?: 'X-Timestamp';
        }
        foreach (['api_key_header', 'hmac_header', 'hmac_timestamp_header'] as $k) {
            if (isset($authConfig[$k]) && preg_match(self::HEADER_NAME, $authConfig[$k]) !== 1) {
                $e[$k === 'api_key_header' ? $k : 'hmac_header'][] = 'Invalid header name.';
            }
        }
        $headers = [];
        $rawHeaders = $in['headers'] ?? '';
        if (is_string($rawHeaders) && trim($rawHeaders) !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                $e['headers'][] = 'Headers must be a JSON object {"Name": "value"}.';
            } else {
                $headers = $decoded;
            }
        } elseif (is_array($rawHeaders)) {
            $headers = $rawHeaders;
        }
        foreach ($headers as $hn => $hv) {
            if (!is_string($hn) || preg_match(self::HEADER_NAME, $hn) !== 1 || !is_scalar($hv) || preg_match('/[\r\n]/', (string) $hv) === 1) {
                $e['headers'][] = 'Invalid header: ' . mb_substr((string) $hn, 0, 64);
                break;
            }
            if (preg_match('/^(authorization|cookie|host|content-length)$/i', $hn) === 1) {
                $e['headers'][] = "Header \"$hn\" cannot be set here (use the authentication settings).";
                break;
            }
        }
        $headers = array_map('strval', $headers);
        $healthPath = $str('health_path');
        if ($healthPath !== '' && (strlen($healthPath) > 512 || preg_match('#^https?://#i', $healthPath) === 1 || !str_starts_with($healthPath, '/'))) {
            $e['health_path'][] = 'A relative path starting with "/" (max 512 characters).';
        }
        $data = [
            'code' => $code,
            'name' => $name,
            'description' => mb_substr($str('description'), 0, 5000),
            'base_url' => $baseUrl === '' ? null : $baseUrl,
            'auth_type' => $authType,
            // empty maps are stored as '{}' (Sql::row would encode [] as a JSON list)
            'auth_config' => $authConfig === [] ? '{}' : $authConfig,
            'headers' => $headers === [] ? '{}' : $headers,
            'timeout_ms' => $int('timeout_ms', 100, 120000, (int) ($current['timeout_ms'] ?? 10000)),
            'connect_timeout_ms' => $int('connect_timeout_ms', 100, 60000, (int) ($current['connect_timeout_ms'] ?? 3000)),
            'max_retries' => $int('max_retries', 0, 10, (int) ($current['max_retries'] ?? 3)),
            'backoff_ms' => $int('backoff_ms', 0, 60000, (int) ($current['backoff_ms'] ?? 200)),
            'circuit_threshold' => $int('circuit_threshold', 1, 1000, (int) ($current['circuit_threshold'] ?? 5)),
            'circuit_cooldown_s' => $int('circuit_cooldown_s', 1, 3600, (int) ($current['circuit_cooldown_s'] ?? 30)),
            'rate_limit_per_minute' => $int('rate_limit_per_minute', 0, 1000000, (int) ($current['rate_limit_per_minute'] ?? 0)),
            'health_path' => $healthPath === '' ? null : $healthPath,
        ];
        if ($data['connect_timeout_ms'] > $data['timeout_ms']) {
            $e['connect_timeout_ms'][] = 'Connect timeout cannot exceed the total timeout.';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $data;
    }
}
