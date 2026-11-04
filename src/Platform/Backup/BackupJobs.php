<?php

declare(strict_types=1);

namespace App\Platform\Backup;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Shared\Domain\Exception\ConflictException;
use Psr\Log\LoggerInterface;

/**
 * Backup job handlers (config/common/jobs/ops.php):
 *   platform.backup.run             logical backup + retention purge (nightly schedule / "Start backup")
 *   platform.backup.verify_latest   restore test of the newest successful backup (weekly schedule)
 *   platform.backup.verify          restore test of one backup ({"backup_id": "..."}; "Verify" button)
 */
final class BackupJobs implements JobHandlerInterface
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $actor = isset($payload['initiated_by']) && is_string($payload['initiated_by']) ? $payload['initiated_by'] : null;
        switch ($context->type) {
            case 'platform.backup.run':
                try {
                    $b = $this->backups->run($actor, (string) ($payload['trigger'] ?? ($actor ? 'manual' : 'scheduled')), $context->jobId, $context->progress(...));
                } catch (ConflictException $e) {
                    throw new PermanentJobFailure($e->getMessage(), 0, $e);
                }
                $this->logger->info('Backup {id} completed ({size} bytes)', ['id' => $b['id'], 'size' => $b['size_bytes']]);
                $purged = $this->backups->purgeExpired();
                if ($purged > 0) {
                    $this->logger->info('Backup retention: {n} expired backups removed', ['n' => $purged]);
                }
                return;
            case 'platform.backup.verify_latest':
                $r = $this->backups->verifyLatest($actor);
                $this->logger->info('Restore verification: {result}', ['result' => $r === null ? 'no backup' : ($r['passed'] ? 'passed' : 'failed')]);
                if ($r !== null && !$r['passed']) {
                    throw new PermanentJobFailure('Restore verification failed: ' . ($r['error'] ?? 'row count mismatch'));
                }
                return;
            case 'platform.backup.verify':
                $id = (string) ($payload['backup_id'] ?? '');
                if ($id === '') {
                    throw new PermanentJobFailure('backup_id is required.');
                }
                $r = $this->backups->verify($id, $actor);
                if (!$r['passed']) {
                    throw new PermanentJobFailure('Restore verification failed: ' . ($r['error'] ?? 'row count mismatch'));
                }
                return;
            default:
                throw new PermanentJobFailure('Unsupported backup job ' . $context->type);
        }
    }
}
