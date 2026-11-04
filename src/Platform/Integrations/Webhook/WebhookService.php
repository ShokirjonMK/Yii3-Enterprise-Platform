<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Webhook;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Monitoring\Metrics;
use App\Platform\Queue\HandlerRegistry;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use App\Shared\Infrastructure\Security\Encrypter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Inbound webhooks: endpoint administration (secret shown once on creation/rotation) and reception.
 *
 * Reception pipeline ({@see receive()}): endpoint lookup (enabled) → size limit → rate limit → HMAC-SHA256
 * signature over "timestamp.body" with timestamp tolerance (constant-time compare) → idempotency through the
 * UNIQUE (endpoint_id, external_id) constraint → job enqueued in the same transaction → 202 Accepted.
 * Invalid signatures are NOT stored as events (an attacker must not be able to pre-claim external ids);
 * they produce a security audit record instead.
 */
final class WebhookService
{
    public const MAX_BODY_BYTES = 1048576;
    public const DEFAULT_JOB = 'platform.webhooks.ack';
    private const RATE_PER_MINUTE = 1200;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly JobQueue $queue,
        private readonly HandlerRegistry $handlers,
        private readonly RedisRateLimiter $limiter,
        private readonly Metrics $metrics,
    ) {}

    public function query(): Query
    {
        return (new Query($this->db))->from('webhook_endpoints');
    }

    public function find(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->query()->where(['id' => $id])->one() : null;
        return $row ?? throw new NotFoundException('Webhook endpoint not found.');
    }

    public function eventsQuery(string $endpointId): Query
    {
        return (new Query($this->db))->from('webhook_events')->where(['endpoint_id' => $endpointId]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{id:string, secret:string} the plaintext secret is returned exactly once
     */
    public function create(string $actorId, array $input): array
    {
        $this->access->assert($actorId, 'integrations.manage');
        $data = $this->validate($input, true);
        if ($this->query()->where(['code' => $data['code']])->exists()) {
            throw ValidationException::single('code', 'This code is already used.');
        }
        $id = Uuid::v7();
        $secret = WebhookSigner::generateSecret();
        $this->db->transaction(function () use ($id, $data, $secret, $actorId): void {
            $this->db->createCommand()->insert('webhook_endpoints', ['id' => $id, 'secret_enc' => $this->encrypter->encrypt($secret, 'webhook'), 'secret_rotated_at' => new Expression('now()')] + $data)->execute();
            $this->audit->record('webhook.endpoint_created', 'webhook_endpoint', $id, null, $data, category: 'config', severity: 'notice', actorId: $actorId);
        });
        return ['id' => $id, 'secret' => $secret];
    }

    /** @param array<string,mixed> $input */
    public function update(string $actorId, string $id, array $input): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $data = $this->validate($input + ['code' => $row['code']], false);
        unset($data['code']);
        $this->db->transaction(function () use ($row, $data, $actorId): void {
            $this->db->createCommand()->update('webhook_endpoints', $data + ['version' => new Expression('version + 1')], ['id' => $row['id']])->execute();
            $this->audit->record('webhook.endpoint_updated', 'webhook_endpoint', $row['id'], array_intersect_key($row, $data), $data, category: 'config', severity: 'notice', actorId: $actorId);
        });
    }

    public function setEnabled(string $actorId, string $id, bool $enabled): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $this->db->transaction(function () use ($row, $enabled, $actorId): void {
            $this->db->createCommand()->update('webhook_endpoints', ['enabled' => $enabled], ['id' => $row['id']])->execute();
            $this->audit->record($enabled ? 'webhook.endpoint_enabled' : 'webhook.endpoint_disabled', 'webhook_endpoint', $row['id'], null, ['enabled' => $enabled], category: 'config', severity: 'notice', actorId: $actorId);
        });
    }

    /** @return string new plaintext secret (shown once) */
    public function rotateSecret(string $actorId, string $id): string
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $secret = WebhookSigner::generateSecret();
        $this->db->transaction(function () use ($row, $secret, $actorId): void {
            $this->db->createCommand()->update('webhook_endpoints', [
                'secret_enc' => $this->encrypter->encrypt($secret, 'webhook'), 'secret_rotated_at' => new Expression('now()'), 'version' => new Expression('version + 1'),
            ], ['id' => $row['id']])->execute();
            $this->audit->record('webhook.secret_rotated', 'webhook_endpoint', $row['id'], null, null, ['code' => $row['code']], category: 'security', severity: 'warning', actorId: $actorId);
        });
        return $secret;
    }

    public function delete(string $actorId, string $id): void
    {
        $this->access->assert($actorId, 'integrations.manage');
        $row = $this->find($id);
        $this->db->transaction(function () use ($row, $actorId): void {
            $this->db->createCommand()->delete('webhook_endpoints', ['id' => $row['id']])->execute();
            $this->audit->record('webhook.endpoint_deleted', 'webhook_endpoint', $row['id'], ['code' => $row['code']], null, category: 'config', severity: 'warning', actorId: $actorId);
        });
    }

    /**
     * Processes one inbound delivery.
     *
     * @param array<string, string> $headers lower-case header name => value
     * @return array{status:int, body:array<string,mixed>}
     */
    public function receive(string $code, array $headers, string $body, ?string $ip = null): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $endpoint = $this->query()->where(['code' => $code, 'enabled' => true])->one();
        if ($endpoint === null) {
            $this->metrics->inc('webhooks_received_total', 'Inbound webhooks', ['endpoint' => 'unknown', 'result' => 'not_found']);
            throw new NotFoundException('Unknown webhook endpoint.');
        }
        $label = ['endpoint' => (string) $endpoint['code']];
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->metrics->inc('webhooks_received_total', 'Inbound webhooks', $label + ['result' => 'too_large']);
            return ['status' => 413, 'body' => ['error' => 'payload_too_large']];
        }
        $rl = $this->limiter->hit('webhook:' . $endpoint['id'], self::RATE_PER_MINUTE, 60);
        if (!$rl->allowed) {
            $this->metrics->inc('webhooks_received_total', 'Inbound webhooks', $label + ['result' => 'rate_limited']);
            throw new TooManyRequestsException('Too many webhook deliveries.', max(1, $rl->resetInSeconds));
        }

        $secret = $endpoint['secret_enc'] !== null ? $this->encrypter->decrypt((string) $endpoint['secret_enc'], 'webhook') : '';
        $reason = $secret === '' ? 'endpoint_has_no_secret' : WebhookSigner::verify(
            $secret,
            $headers[strtolower((string) $endpoint['timestamp_header'])] ?? null,
            $body,
            $headers[strtolower((string) $endpoint['signature_header'])] ?? null,
            (int) $endpoint['tolerance_seconds'],
        );
        if ($reason !== null) {
            $this->audit->security('webhook.signature_invalid', 'failure', ['endpoint' => $endpoint['code'], 'reason' => $reason, 'ip' => $ip, 'bytes' => strlen($body)], 'warning');
            $this->metrics->inc('webhooks_received_total', 'Inbound webhooks', $label + ['result' => 'invalid_signature']);
            return ['status' => 401, 'body' => ['error' => 'invalid_signature']];
        }

        $decoded = json_decode($body, true);
        $externalId = $headers[strtolower((string) ($endpoint['id_header'] ?? 'x-webhook-id'))] ?? $headers['idempotency-key'] ?? $headers['x-event-id'] ?? null;
        if (($externalId === null || $externalId === '') && is_array($decoded)) {
            $externalId = $decoded['id'] ?? $decoded['event_id'] ?? null;
        }
        $externalId = $externalId === null || $externalId === '' || !is_scalar($externalId) ? 'sha256:' . hash('sha256', $body) : mb_substr((string) $externalId, 0, 255);
        $payload = is_array($decoded) ? $decoded : ['_raw' => mb_substr($body, 0, 65536)];

        $result = $this->db->transaction(function () use ($endpoint, $externalId, $payload): array {
            $eventId = $this->db->createCommand(
                'INSERT INTO webhook_events (endpoint_id, external_id, signature_valid, payload) VALUES (:e, :x, true, CAST(:p AS jsonb))
                 ON CONFLICT ON CONSTRAINT webhook_events_idempotent DO NOTHING RETURNING id',
                [':e' => $endpoint['id'], ':x' => $externalId, ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
            )->queryScalar();
            $this->db->createCommand()->update('webhook_endpoints', ['last_received_at' => new Expression('now()')], ['id' => $endpoint['id']])->execute();
            if ($eventId === false || $eventId === null) {
                return ['duplicate' => true, 'event_id' => null, 'job_id' => null];
            }
            $jobType = (string) ($endpoint['job_type'] ?: self::DEFAULT_JOB);
            $jobId = null;
            if ($this->handlers->has($jobType)) {
                $jobId = $this->queue->push($jobType, [
                    'webhook_event_id' => (int) $eventId, 'endpoint' => $endpoint['code'], 'external_id' => $externalId,
                ], ['idempotency_key' => 'webhook:' . $endpoint['id'] . ':' . $externalId]);
                $this->db->createCommand()->update('webhook_events', ['job_id' => $jobId], ['id' => $eventId])->execute();
            }
            return ['duplicate' => false, 'event_id' => (int) $eventId, 'job_id' => $jobId];
        });

        $this->metrics->inc('webhooks_received_total', 'Inbound webhooks', $label + ['result' => $result['duplicate'] ? 'duplicate' : 'accepted']);
        if ($result['duplicate']) {
            return ['status' => 200, 'body' => ['status' => 'duplicate', 'external_id' => $externalId]];
        }
        return ['status' => 202, 'body' => ['status' => 'accepted', 'event_id' => $result['event_id'], 'external_id' => $externalId, 'queued' => $result['job_id'] !== null]];
    }

    /** @return array<string,mixed> */
    private function validate(array $in, bool $creating): array
    {
        $e = [];
        $code = strtolower(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) !== 1) {
            $e['code'][] = '2–64 characters: lowercase letters, digits, "-" and "_".';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $e['name'][] = 'Name is required (max 255 characters).';
        }
        $headerRe = '/^[A-Za-z0-9-]{1,64}$/';
        $sig = trim((string) ($in['signature_header'] ?? '')) ?: 'X-Signature';
        $ts = trim((string) ($in['timestamp_header'] ?? '')) ?: 'X-Timestamp';
        $idh = trim((string) ($in['id_header'] ?? '')) ?: 'X-Webhook-Id';
        foreach (['signature_header' => $sig, 'timestamp_header' => $ts, 'id_header' => $idh] as $k => $v) {
            if (preg_match($headerRe, $v) !== 1) {
                $e[$k][] = 'Invalid header name.';
            }
        }
        $tol = filter_var($in['tolerance_seconds'] ?? 300, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 3600]]);
        if ($tol === false) {
            $e['tolerance_seconds'][] = 'Must be between 30 and 3600 seconds.';
        }
        $job = trim((string) ($in['job_type'] ?? ''));
        if ($job !== '' && !$this->handlers->has($job)) {
            $e['job_type'][] = 'No job handler is registered for this type.';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $data = [
            'code' => $code, 'name' => $name, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000),
            'signature_header' => $sig, 'timestamp_header' => $ts, 'id_header' => $idh,
            'tolerance_seconds' => (int) $tol, 'job_type' => $job === '' ? null : $job,
        ];
        if ($creating) {
            $data['enabled'] = filter_var($in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        return $data;
    }
}
