<?php

declare(strict_types=1);

namespace App\Platform\Maintenance;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\JobQueue;
use App\Platform\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * System maintenance jobs scheduled by PlatformSeeder (config/common/jobs.php):
 *   platform.maintenance.partitions   create upcoming audit partitions, detach expired ones
 *   platform.maintenance.cleanup      retention policies (login attempts, inbox, logs, finished jobs, sessions)
 *   platform.flags.apply_schedule     scheduled feature-flag rollouts
 *   platform.queue.reap               recover jobs of crashed workers
 */
final class MaintenanceJobs implements JobHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsService $settings,
        private readonly FeatureFlagService $flags,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        match ($context->type) {
            'platform.maintenance.partitions' => $this->partitions(),
            'platform.maintenance.cleanup' => $this->cleanup(),
            'platform.flags.apply_schedule' => $this->flags->applySchedules(),
            'platform.queue.reap' => $this->queue->reap(),
            default => throw new \App\Platform\Queue\PermanentJobFailure('Unsupported maintenance job ' . $context->type),
        };
    }

    public function partitions(): array
    {
        $created = (int) $this->db->createCommand("SELECT platform_ensure_monthly_partitions('audit_log', 0, 3)")->queryScalar();
        foreach (['user_activity', 'app_logs'] as $table) {
            $created += (int) $this->db->createCommand("SELECT platform_ensure_monthly_partitions('$table', 0, 3)")->queryScalar();
        }
        $retention = (int) $this->settings->get('retention.audit_months');
        $cutoff = (new \DateTimeImmutable("first day of -$retention months"))->format('Ym');
        $detached = [];
        foreach ($this->db->createCommand("SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_class p ON p.oid = i.inhparent
                WHERE p.relname = 'audit_log' AND c.relname ~ '^audit_log_p[0-9]{6}$' ORDER BY c.relname")->queryColumn() as $part) {
            if (substr((string) $part, -6) < $cutoff) {
                // Detached partitions are kept as standalone tables for archival export, never dropped automatically
                $this->db->createCommand('ALTER TABLE audit_log DETACH PARTITION ' . $this->db->getQuoter()->quoteTableName((string) $part))->execute();
                $detached[] = $part;
            }
        }
        $this->logger->info('Audit partitions: {c} created, {d} detached', ['c' => $created, 'd' => count($detached)]);
        return ['created' => $created, 'detached' => $detached];
    }

    /** @return array<string,int> */
    public function cleanup(): array
    {
        $days = fn(string $key) => max(1, (int) $this->settings->get($key));
        $r = [];
        $r['login_attempts'] = $this->db->createCommand('DELETE FROM login_attempts WHERE created_at < now() - make_interval(days => :d)', [':d' => $days('retention.login_attempts_days')])->execute();
        $r['integration_logs'] = $this->db->createCommand('DELETE FROM integration_logs WHERE created_at < now() - make_interval(days => :d)', [':d' => $days('retention.integration_logs_days')])->execute();
        $r['jobs'] = $this->db->createCommand("DELETE FROM jobs WHERE status IN ('succeeded','cancelled') AND finished_at < now() - make_interval(days => :d)", [':d' => $days('retention.jobs_days')])->execute();
        $r['inbox'] = $this->db->createCommand("DELETE FROM inbox WHERE processed_at < now() - interval '14 days'")->execute();
        $r['outbox'] = $this->db->createCommand("DELETE FROM outbox WHERE status = 'published' AND published_at < now() - interval '7 days'")->execute();
        $r['sessions'] = $this->db->createCommand("DELETE FROM user_sessions WHERE expires_at < now() - interval '30 days'")->execute();
        $r['api_tokens'] = $this->db->createCommand("DELETE FROM api_tokens WHERE expires_at < now() - interval '30 days'")->execute();
        $r['bootstrap_tokens'] = $this->db->createCommand('DELETE FROM bootstrap_tokens WHERE expires_at < now()')->execute();
        $r['workers'] = $this->db->createCommand("DELETE FROM workers WHERE status IN ('stopped','lost') AND heartbeat_at < now() - interval '1 day'")->execute();
        $r['user_activity'] = $this->db->createCommand('DELETE FROM user_activity WHERE occurred_at < now() - make_interval(days => :d)', [':d' => $days('retention.activity_days')])->execute();
        $r['app_logs'] = $this->db->createCommand('DELETE FROM app_logs WHERE occurred_at < now() - make_interval(days => :d)', [':d' => $days('retention.app_logs_days')])->execute();
        $r['schedule_runs'] = $this->db->createCommand("DELETE FROM schedule_runs WHERE created_at < now() - interval '30 days'")->execute();
        return $r;
    }
}
