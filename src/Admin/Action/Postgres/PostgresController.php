<?php

declare(strict_types=1);

namespace App\Admin\Action\Postgres;

use App\Platform\Database\PostgresInspector;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;

/**
 * 12 · PostgreSQL Management — read-only diagnostics (db.view) and safe operations (db.manage + step-up):
 * ANALYZE / VACUUM (ANALYZE) of a whitelisted table, pg_cancel_backend for application-owned backends,
 * partition maintenance job. No terminate/drop/truncate exists by design.
 */
final class PostgresController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PostgresInspector $pg,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('admin/postgres/index', [
            'overview' => $this->pg->overview(),
            'states' => $this->pg->connectionsByState(),
            'long' => $this->pg->longRunning(30),
            'blocking' => $this->pg->blocking(),
            'replicas' => $this->pg->replication()['replicas'],
            'statements' => $this->pg->statementsStatus(),
        ]);
    }

    public function activity(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $active = ($qp['active'] ?? '1') === '1';
        $min = max(0, min(86400, (int) ($qp['min'] ?? 0)));
        return $this->responder->render('admin/postgres/activity', [
            'rows' => $this->pg->activity(300, $min, $active),
            'blocking' => $this->pg->blocking(),
            'locks' => $this->pg->lockSummary(),
            'active' => $active,
            'min' => $min,
        ]);
    }

    public function queries(ServerRequestInterface $request): ResponseInterface
    {
        $order = (string) ($request->getQueryParams()['order'] ?? 'mean');
        $order = in_array($order, ['mean', 'total', 'calls', 'rows'], true) ? $order : 'mean';
        return $this->responder->render('admin/postgres/queries', [
            'status' => $this->pg->statementsStatus(),
            'rows' => $this->pg->slowQueries($order, 50),
            'order' => $order,
        ]);
    }

    public function storage(ServerRequestInterface $request): ResponseInterface
    {
        $order = (string) ($request->getQueryParams()['order'] ?? 'size');
        $order = in_array($order, ['size', 'dead', 'seq', 'name'], true) ? $order : 'size';
        return $this->responder->render('admin/postgres/storage', [
            'tables' => $this->pg->tables(100, $order),
            'indexes' => $this->pg->indexes(50),
            'unused' => $this->pg->unusedIndexes(),
            'bloat' => $this->pg->bloat(),
            'maintainable' => $this->pg->maintainableTables(),
            'order' => $order,
        ]);
    }

    public function replication(): ResponseInterface
    {
        return $this->responder->render('admin/postgres/replication', ['r' => $this->pg->replication(), 'overview' => $this->pg->overview()]);
    }

    public function partitions(): ResponseInterface
    {
        return $this->responder->render('admin/postgres/partitions', ['partitions' => $this->pg->partitions('audit_log')]);
    }

    public function maintain(ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        $table = (string) ($b['table'] ?? '');
        $op = (string) ($b['operation'] ?? '');
        try {
            $this->pg->maintain($table, $op, $this->currentUser->getId());
            $this->responder->success(sprintf('%s completed on %s.', $op === 'vacuum' ? 'VACUUM (ANALYZE)' : 'ANALYZE', $table));
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.postgres.storage');
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $pid = filter_var(((array) $request->getParsedBody())['pid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($pid === false) {
            throw ValidationException::single('pid', 'Invalid backend pid.');
        }
        try {
            $ok = $this->pg->cancelBackend($pid, $this->currentUser->getId());
            $ok ? $this->responder->success(sprintf('Cancel signal sent to backend %d.', $pid)) : $this->responder->error('The backend could not be signalled.');
        } catch (NotFoundException $e) {
            $this->responder->error($e->getMessage());
        }
        // AccessDeniedException (foreign pid) propagates → 403 page; the refusal is audited by the inspector.
        return $this->responder->redirect('admin.postgres.activity');
    }

    public function runPartitions(): ResponseInterface
    {
        $jobId = $this->pg->enqueuePartitionMaintenance($this->currentUser->getId());
        $this->responder->success('Partition maintenance job enqueued.');
        return $this->responder->redirect('admin.jobs.view', ['id' => $jobId]);
    }
}
