<?php

declare(strict_types=1);

namespace App\Modules\Projects\Infrastructure;

use App\Platform\Audit\AuditLogger;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Infrastructure\Database\Sql;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Job `projects.report.generate`: builds a CSV summary of a tenant's projects and stores it in
 * object storage under reports/projects/<tenant>/<date>-<report>.csv. Progress and the outcome are
 * written to `project_reports` (shown in Admin/Web/API).
 */
final class GenerateProjectsReportJob implements JobHandlerInterface
{
    private const COLUMNS = ['code', 'name', 'status', 'priority', 'owner', 'budget', 'starts_on', 'due_on', 'tags', 'members', 'created_at', 'updated_at'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ObjectStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $reportId = (string) ($payload['report_id'] ?? '');
        $report = (new Query($this->db))->from('project_reports')->where(['id' => $reportId])->one();
        if ($report === null) {
            throw new PermanentJobFailure('Report record not found: ' . $reportId);
        }
        if ($report['status'] === 'done') {
            return; // idempotent re-run
        }
        if (!$this->storage->isConfigured()) {
            $this->fail($reportId, 'Object storage is not configured.');
            throw new PermanentJobFailure('Object storage is not configured.');
        }
        $this->db->createCommand()->update('project_reports', ['status' => 'running', 'error' => null], ['id' => $reportId])->execute();

        try {
            $tenantId = (string) $report['tenant_id'];
            $filters = Sql::json($report['filters']);
            $q = (new Query($this->db))
                ->select([
                    'p.code', 'p.name', 'p.status', 'p.priority', 'owner' => new Expression("COALESCE(NULLIF(u.full_name, ''), u.username)"),
                    'p.budget', 'p.starts_on', 'p.due_on', 'p.tags',
                    'members' => new Expression('(SELECT count(*) FROM project_members m WHERE m.project_id = p.id)'),
                    'p.created_at', 'p.updated_at',
                ])
                ->from(['p' => 'projects'])->leftJoin(['u' => 'users'], 'u.id = p.owner_id')
                ->where(['p.tenant_id' => $tenantId])
                ->orderBy(['p.code' => SORT_ASC]);
            if (($filters['status'] ?? '') !== '') {
                $q->andWhere(['p.status' => explode(',', (string) $filters['status'])]);
            }
            if (($filters['priority'] ?? '') !== '') {
                $q->andWhere(['p.priority' => explode(',', (string) $filters['priority'])]);
            }
            if (($filters['q'] ?? '') !== '') {
                $q->andWhere(Sql::search((string) $filters['q'], ['p.code', 'p.name']));
            }

            $total = max(1, (int) (clone $q)->count());
            $out = fopen('php://temp', 'w+b');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet tools
            fputcsv($out, self::COLUMNS, ',', '"', '');
            $rows = 0;
            $totals = [];
            $budget = 0.0;
            foreach ($q->batch(500) as $batch) {
                $context->throwIfCancelled();
                foreach ($batch as $r) {
                    $r['tags'] = implode(' ', Sql::json($r['tags']));
                    fputcsv($out, array_map(static fn($v) => self::safeCell($v), array_values($r)), ',', '"', '');
                    $rows++;
                    $totals[$r['status']] = ($totals[$r['status']] ?? 0) + 1;
                    $budget += (float) ($r['budget'] ?? 0);
                }
                $context->progress((int) floor($rows / $total * 90));
            }
            fputcsv($out, [], ',', '"', '');
            fputcsv($out, ['summary', 'total', (string) $rows], ',', '"', '');
            foreach ($totals as $status => $n) {
                fputcsv($out, ['summary', (string) $status, (string) $n], ',', '"', '');
            }
            fputcsv($out, ['summary', 'budget_total', number_format($budget, 2, '.', '')], ',', '"', '');
            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);

            $path = sprintf('reports/projects/%s/%s-%s.csv', $tenantId, gmdate('Ymd-His'), $reportId);
            try {
                $this->storage->ensureBucket();
            } catch (Throwable) {
                // bucket may exist but HeadBucket be forbidden; the write below decides
            }
            $this->storage->filesystem()->write($path, $csv, ['ContentType' => 'text/csv']);

            $this->db->createCommand()->update('project_reports', [
                'status' => 'done',
                'path' => $path,
                'rows_count' => $rows,
                'size_bytes' => strlen($csv),
                'checksum_sha256' => hash('sha256', $csv),
                'finished_at' => new Expression('now()'),
            ], ['id' => $reportId])->execute();
            $this->audit->record('project.report.generated', 'project_report', $reportId, null, ['path' => $path, 'rows' => $rows], actorId: $report['requested_by'] ?? null);
            $context->progress(100);
        } catch (Throwable $e) {
            $this->fail($reportId, $e->getMessage());
            throw $e;
        }
    }

    /** Neutralises spreadsheet formula injection (CSV injection, OWASP). */
    public static function safeCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s) ? "'" . $s : $s;
    }

    private function fail(string $reportId, string $error): void
    {
        $this->db->createCommand()->update('project_reports', [
            'status' => 'failed',
            'error' => mb_substr($error, 0, 1000),
            'finished_at' => new Expression('now()'),
        ], ['id' => $reportId])->execute();
    }
}
