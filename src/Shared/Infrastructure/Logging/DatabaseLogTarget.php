<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Container\ContainerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Log\Target;

/**
 * Stores warning/error/critical log records in `app_logs` for the admin log viewer (Monitoring → Logs).
 * The JSON stderr stream remains the primary log pipeline; this target is an operator convenience and can be
 * switched off with the feature flag `logging.database`.
 *
 * Safe by construction: re-entrancy guard (a failing insert never logs itself), all context passes through
 * the Redactor, failures are swallowed, the flag is read without the full FeatureFlagService (no recursion).
 *
 * Author: ShokirjonMK
 */
final class DatabaseLogTarget extends Target
{
    private static bool $writing = false;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RequestContext $context = null,
        array $levels = ['emergency', 'alert', 'critical', 'error', 'warning'],
    ) {
        parent::__construct($levels);
    }

    protected function export(): void
    {
        if (self::$writing) {
            return;
        }
        self::$writing = true;
        try {
            /** @var ConnectionInterface $db */
            $db = $this->container->get(ConnectionInterface::class);
            if (!$this->enabled($db)) {
                return;
            }
            $rows = [];
            foreach ($this->getMessages() as $m) {
                $ctx = $m->context();
                $exception = $ctx['exception'] ?? $ctx['throwable'] ?? null;
                unset($ctx['time'], $ctx['trace'], $ctx['memory'], $ctx['category'], $ctx['exception'], $ctx['throwable']);
                if ($exception !== null) {
                    $ctx['exception'] = $exception;
                }
                $rows[] = [
                    'occurred_at' => $m->time()->format('Y-m-d H:i:s.uP'),
                    'level' => $m->level(),
                    'category' => mb_substr($m->category(), 0, 128),
                    'message' => mb_substr(Redactor::redactString($m->message()), 0, 10000),
                    'context' => json_encode(Redactor::redact($ctx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                    'channel' => $this->context?->channel(),
                    'user_id' => $this->context?->userId(),
                    'tenant_id' => $this->context?->tenantId(),
                    'correlation_id' => $this->context?->correlationId(),
                    'host' => mb_substr((string) gethostname(), 0, 128),
                ];
            }
            if ($rows !== []) {
                $db->createCommand()->insertBatch('app_logs', $rows)->execute();
            }
        } catch (Throwable) {
            // never let log persistence fail the request
        } finally {
            self::$writing = false;
        }
    }

    private function enabled(ConnectionInterface $db): bool
    {
        static $cache = null;
        static $at = 0.0;
        if ($cache === null || microtime(true) - $at > 10) {
            $v = $db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'logging.database'")->queryScalar();
            $cache = $v === true || $v === 't' || $v === 1 || $v === '1';
            $at = microtime(true);
        }
        return $cache;
    }
}
