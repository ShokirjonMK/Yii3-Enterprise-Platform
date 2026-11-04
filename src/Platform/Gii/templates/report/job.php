<?php

declare(strict_types=1);

/**
 * Template: CSV report job (src/Modules/<Module>/Infrastructure/Generate<Module>ReportJob.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$columns = ['id', ...array_map(static fn($f) => $f->name, $m->fields), 'created_at', 'updated_at'];
$select = array_map(static fn($c) => $q('e.' . $c), $columns);
$filters = [];
foreach ($m->filterable() as $f) {
    if ($f->type === 'bool') {
        continue;
    }
    $k = $q($f->name);
    $filters[] = "            if ((string) (\$filters[$k] ?? '') !== '') {\n                \$q->andWhere(['e.{$f->name}' => explode(',', (string) \$filters[$k])]);\n            }";
}
if ($m->searchable() !== []) {
    $cols = '[' . implode(', ', array_map(static fn($f) => $q('e.' . $f->name), $m->searchable())) . ']';
    $filters[] = "            if ((string) (\$filters['q'] ?? '') !== '') {\n                \$q->andWhere(Sql::search((string) \$filters['q'], $cols));\n            }";
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Infrastructure;

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
 * Job `__JOB__`: streams the tenant's {{labelLower}} into a CSV (UTF-8 BOM, formula-injection safe) and stores
 * it in object storage under reports/{{slug}}/<tenant>/<date>-<report>.csv. Progress and outcome are written
 * to {{entity_snake}}_reports (status page in Admin). Re-running a finished report is a no-op.
 */
final class Generate{{Module}}ReportJob implements JobHandlerInterface
{
    public const TYPE = '__JOB__';
    public const COLUMNS = __COLUMNS__;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ObjectStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $reportId = (string) ($payload['report_id'] ?? '');
        $report = (new Query($this->db))->from('{{entity_snake}}_reports')->where(['id' => $reportId])->one();
        if ($report === null) {
            throw new PermanentJobFailure('Report record not found: ' . $reportId);
        }
        if ($report['status'] === 'done') {
            return;
        }
        if (!$this->storage->isConfigured()) {
            $this->fail($reportId, 'Object storage is not configured.');
            throw new PermanentJobFailure('Object storage is not configured.');
        }
        $this->db->createCommand()->update('{{entity_snake}}_reports', ['status' => 'running', 'error' => null], ['id' => $reportId])->execute();

        try {
            $tenantId = (string) $report['tenant_id'];
            $filters = Sql::json($report['filters']);
            $q = (new Query($this->db))->select([__SELECT__])->from(['e' => '{{table}}'])
                ->where(['e.tenant_id' => $tenantId, 'e.deleted_at' => null])
                ->orderBy(['e.created_at' => SORT_ASC]);
__FILTERS__
            $total = max(1, (int) (clone $q)->count());
            $out = fopen('php://temp', 'w+b');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS, ',', '"', '');
            $rows = 0;
            foreach ($q->batch(500) as $batch) {
                $context->throwIfCancelled();
                foreach ($batch as $r) {
                    fputcsv($out, array_map(static fn($v) => self::safeCell(is_bool($v) ? ($v ? 'true' : 'false') : $v), array_values($r)), ',', '"', '');
                    $rows++;
                }
                $context->progress((int) floor($rows / $total * 90));
            }
            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);

            $path = sprintf('reports/{{slug}}/%s/%s-%s.csv', $tenantId, gmdate('Ymd-His'), $reportId);
            try {
                $this->storage->ensureBucket();
            } catch (Throwable) {
                // the bucket may exist while HeadBucket is forbidden; the write decides
            }
            $this->storage->filesystem()->write($path, $csv, ['ContentType' => 'text/csv']);
            $this->db->createCommand()->update('{{entity_snake}}_reports', [
                'status' => 'done',
                'path' => $path,
                'rows_count' => $rows,
                'size_bytes' => strlen($csv),
                'checksum_sha256' => hash('sha256', $csv),
                'finished_at' => new Expression('now()'),
            ], ['id' => $reportId])->execute();
            $this->audit->record('{{entity_snake}}.report.generated', '{{entity_snake}}_report', $reportId, null, ['path' => $path, 'rows' => $rows], actorId: $report['requested_by'] ?? null);
            $context->progress(100);
        } catch (Throwable $e) {
            $this->fail($reportId, $e->getMessage());
            throw $e;
        }
    }

    /** Neutralises spreadsheet formula injection (OWASP CSV injection). */
    public static function safeCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s) ? "'" . $s : $s;
    }

    private function fail(string $reportId, string $error): void
    {
        $this->db->createCommand()->update('{{entity_snake}}_reports', [
            'status' => 'failed',
            'error' => mb_substr($error, 0, 1000),
            'finished_at' => new Expression('now()'),
        ], ['id' => $reportId])->execute();
    }
}

PHP, [
    '__JOB__' => $m->reportJob(),
    '__COLUMNS__' => '[' . implode(', ', array_map($q, $columns)) . ']',
    '__SELECT__' => implode(', ', $select),
    '__FILTERS__' => implode("\n", $filters),
]);
