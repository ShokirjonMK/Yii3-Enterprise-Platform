<?php

declare(strict_types=1);

/**
 * Template: worker job (src/Modules/<Module>/Infrastructure/PurgeDeleted<Module>Job.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Infrastructure;

use App\Platform\Audit\AuditLogger;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Job `__JOB__`: permanently removes {{labelLower}} that were soft-deleted more than `retention_days`
 * (payload, default 90, minimum 1) ago. Works in batches of 500 inside short transactions, honours
 * cancellation, reports progress and writes one audit record with the number of purged rows.
 * Idempotent: re-running only finds what is still eligible.
 */
final class PurgeDeleted{{Module}}Job implements JobHandlerInterface
{
    public const TYPE = '__JOB__';
    public const DEFAULT_RETENTION_DAYS = 90;
    private const BATCH = 500;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $days = max(1, (int) ($payload['retention_days'] ?? self::DEFAULT_RETENTION_DAYS));
        $total = (int) $this->db->createCommand(
            "SELECT count(*) FROM {{table}} WHERE deleted_at IS NOT NULL AND deleted_at < now() - make_interval(days => :d)",
            [':d' => $days],
        )->queryScalar();
        $purged = 0;
        while (true) {
            $context->throwIfCancelled();
            $n = (int) $this->db->transaction(fn() => $this->db->createCommand(
                "DELETE FROM {{table}} WHERE id IN (
                    SELECT id FROM {{table}} WHERE deleted_at IS NOT NULL AND deleted_at < now() - make_interval(days => :d)
                    ORDER BY deleted_at LIMIT " . self::BATCH . ' FOR UPDATE SKIP LOCKED)',
                [':d' => $days],
            )->execute());
            $purged += $n;
            if ($total > 0) {
                $context->progress((int) min(99, floor($purged / $total * 100)));
            }
            if ($n < self::BATCH) {
                break;
            }
        }
        $this->audit->record('{{entity_snake}}.purged', '{{entity_snake}}', null, null, ['purged' => $purged], ['retention_days' => $days], category: 'data', severity: 'notice');
        $context->progress(100);
    }
}

PHP, ['__JOB__' => $m->cleanupJob()]);
