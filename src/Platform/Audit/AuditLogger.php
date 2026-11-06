<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\Security\Encrypter;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Writes immutable audit records. Every record is HMAC-signed so tampering with stored rows
 * (even by a DBA) is detectable with {@see AuditVerifier}.
 *
 * Audit writes join the caller's DB transaction when one is open — the business change and its
 * audit record are committed atomically.
 */
final class AuditLogger
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RequestContext $context,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed> $metadata
     */
    public function record(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        string $result = 'success',
        string $category = 'data',
        string $severity = 'info',
        ?string $actorId = null,
        ?string $actorName = null,
    ): void {
        $row = [
            'occurred_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            'category' => $category,
            'action' => $action,
            'result' => $result,
            'severity' => $severity,
            'actor_id' => $actorId ?? $this->context->userId(),
            'actor_name' => $actorName,
            'actor_type' => ($actorId ?? $this->context->userId()) === null ? 'system' : 'user',
            'tenant_id' => $this->context->tenantId(),
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'ip' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'correlation_id' => $this->context->correlationId(),
            'before' => $before === null ? null : self::encode(Redactor::redact($before)),
            'after' => $after === null ? null : self::encode(Redactor::redact($after)),
            'metadata' => self::encode(Redactor::redact($metadata + ['channel' => $this->context->channel()])),
        ];
        $row['row_hmac'] = $this->encrypter->hmac(self::canonical($row), 'audit');

        try {
            $this->db->createCommand()->insert('audit_log', $row)->execute();
        } catch (Throwable $e) {
            // Audit must never be silently lost: log loudly to the structured log stream as a fallback,
            // then rethrow so the surrounding business transaction is rolled back.
            $this->logger->critical('Audit write failed', ['action' => $action, 'exception' => $e]);
            throw $e;
        }
    }

    public function security(string $action, string $result = 'success', array $metadata = [], string $severity = 'notice', ?string $actorId = null, ?string $actorName = null): void
    {
        $this->record($action, 'security', null, null, null, $metadata, $result, 'security', $severity, $actorId, $actorName);
    }

    /** @param array<string,mixed> $row */
    public static function canonical(array $row): string
    {
        $fields = ['occurred_at', 'category', 'action', 'result', 'actor_id', 'tenant_id', 'entity_type', 'entity_id', 'ip', 'correlation_id', 'before', 'after', 'metadata'];
        $parts = [];
        foreach ($fields as $f) {
            $v = $row[$f] ?? null;
            if ($f === 'before' || $f === 'after' || $f === 'metadata') {
                // jsonb normalises key order/whitespace, so compare on decoded+re-encoded form
                $v = $v === null ? null : self::encode(self::sortKeys(json_decode((string) $v, true)));
            }
            if ($f === 'occurred_at' && $v !== null) {
                $v = (new DateTimeImmutable((string) $v))->format('U.u');
            }
            $parts[] = $f . '=' . ($v === null ? '' : (string) $v);
        }
        return implode("\x1f", $parts);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            ksort($v);
        }
        return array_map(self::sortKeys(...), $v);
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
