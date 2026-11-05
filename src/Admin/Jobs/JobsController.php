<?php

declare(strict_types=1);

namespace App\Admin\Jobs;

use App\Platform\Audit\AuditLogger;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 10 · Jobs & Scheduler — queues, jobs, failed/dead jobs, workers.
 * Operator actions (retry, cancel, pause) go through JobQueue, which writes the audit trail.
 */
final class JobsController
{
    private const BULK_LIMIT = 500;

    public function __construct(
        private readonly Responder $responder,
        private readonly JobsReadModel $read,
        private readonly JobQueue $queue,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $filters = $this->filters($qp);
        return $this->responder->render('@admin/Jobs/views/index', [
            'stats' => $this->queue->stats(),
            'totals' => $this->read->totals(),
            'page' => $this->read->jobs($filters, (int) ($qp['page'] ?? 1), (string) ($qp['sort'] ?? '')),
            'filters' => $filters,
            'sort' => (string) ($qp['sort'] ?? ''),
            'queues' => $this->read->queues(),
            'types' => $this->read->types(),
        ]);
    }

    public function failed(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $filters = $this->filters($qp);
        if (!in_array($filters['status'], ['failed', 'dead', 'cancelled'], true)) {
            $filters['status'] = '';
        }
        $query = $filters;
        $query['status'] = $filters['status'] === '' ? ['failed', 'dead'] : $filters['status'];
        return $this->responder->render('@admin/Jobs/views/failed', [
            'page' => $this->read->jobs($query, (int) ($qp['page'] ?? 1), (string) ($qp['sort'] ?? ''), 50),
            'filters' => $filters,
            'sort' => (string) ($qp['sort'] ?? ''),
            'queues' => $this->read->queues(),
            'types' => $this->read->types(),
        ]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $job = $this->read->job((string) $route->getArgument('id'));
        return $this->responder->render('@admin/Jobs/views/view', [
            'job' => $job,
            'attempts' => $this->read->attempts((string) $job['id']),
        ]);
    }

    public function workers(ServerRequestInterface $request): ResponseInterface
    {
        $filter = (string) ($request->getQueryParams()['liveness'] ?? 'all');
        $filter = in_array($filter, ['all', 'alive', 'lost', 'stopped'], true) ? $filter : 'all';
        return $this->responder->render('@admin/Jobs/views/workers', [
            'workers' => $this->read->workers($filter),
            'filter' => $filter,
        ]);
    }

    public function retry(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->queue->retry($id, $this->currentUser->getId());
            $this->responder->success('Job re-queued.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.jobs.view', ['id' => $id]);
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->queue->cancel($id, $this->currentUser->getId());
            $this->responder->success('Cancellation requested.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.jobs.view', ['id' => $id]);
    }

    /** Bulk retry of selected ids, or of every failed/dead job matching the current filter (bounded). */
    public function retryBulk(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (($body['scope'] ?? '') === 'filter') {
            $filters = $this->filters($body);
            $filters['status'] = in_array($filters['status'], ['failed', 'dead', 'cancelled'], true) ? $filters['status'] : ['failed', 'dead'];
            $ids = $this->read->ids($filters, self::BULK_LIMIT);
        } else {
            $ids = array_slice(array_values(array_filter(array_map('strval', (array) ($body['ids'] ?? [])))), 0, self::BULK_LIMIT);
        }
        if ($ids === []) {
            $this->responder->info('No jobs selected.');
            return $this->responder->redirect('admin.jobs.failed');
        }
        $actor = $this->currentUser->getId();
        $ok = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            try {
                $this->queue->retry($id, $actor);
                $ok++;
            } catch (ConflictException|\App\Shared\Domain\Exception\NotFoundException) {
                $skipped++;
            }
        }
        $this->audit->record('job.bulk_retried', 'job', null, metadata: ['retried' => $ok, 'skipped' => $skipped], category: 'operations', severity: 'notice', actorId: $actor);
        $this->responder->success(sprintf('%d job(s) re-queued, %d skipped.', $ok, $skipped));
        return $this->responder->redirect('admin.jobs.failed');
    }

    public function pauseQueue(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $queue = (string) $route->getArgument('queue');
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $queue)) {
            throw ValidationException::single('queue', 'Invalid queue name.');
        }
        $paused = filter_var(((array) $request->getParsedBody())['paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->queue->pauseQueue($queue, $paused, $this->currentUser->getId());
        $this->responder->success($paused ? sprintf('Queue "%s" paused.', $queue) : sprintf('Queue "%s" resumed.', $queue));
        return $this->responder->redirect('admin.jobs');
    }

    /** @return array{queue:string, status:string, type:string, q:string} */
    private function filters(array $qp): array
    {
        $status = (string) ($qp['status'] ?? '');
        return [
            'queue' => mb_substr(trim((string) ($qp['queue'] ?? '')), 0, 64),
            'status' => in_array($status, JobsReadModel::STATUSES, true) ? $status : '',
            'type' => mb_substr(trim((string) ($qp['type'] ?? '')), 0, 255),
            'q' => mb_substr(trim((string) ($qp['q'] ?? '')), 0, 100),
        ];
    }
}
