<?php

declare(strict_types=1);

namespace App\Admin\Backups;

use App\Platform\Audit\AuditLogger;
use App\Platform\Backup\BackupService;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 19 · Backup & Recovery. Backups and restore tests run as queue jobs (long-running; never inside a
 * PHP-FPM request); the UI shows their progress from the backups / jobs tables.
 */
final class BackupsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly BackupService $backups,
        private readonly JobQueue $queue,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        return $this->responder->render('@admin/Backups/views/index', [
            'page' => $this->backups->list((int) ($q['page'] ?? 1), (string) ($q['sort'] ?? '')),
            'summary' => $this->backups->summary(),
            'wal' => $this->backups->walStatus(),
            'jobs' => (new Query($this->db))->select(['id', 'type', 'status', 'progress', 'attempts', 'last_error', 'created_at', 'finished_at'])->from('jobs')
                ->where(['type' => ['platform.backup.run', 'platform.backup.verify', 'platform.backup.verify_latest']])
                ->orderBy(['created_at' => SORT_DESC])->limit(8)->all(),
            'schedules' => (new Query($this->db))->select(['code', 'cron_expression', 'enabled', 'last_run_at', 'next_run_at', 'last_status'])->from('schedules')
                ->where(['code' => ['platform.backup', 'platform.backup.verify']])->all(),
        ]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->responder->render('@admin/Backups/views/view', ['b' => $this->backups->get((string) $route->getArgument('id'))]);
    }

    public function start(): ResponseInterface
    {
        $actor = $this->actor();
        $jobId = $this->queue->push('platform.backup.run', ['initiated_by' => $actor->id, 'trigger' => 'manual'], ['max_attempts' => 1, 'timeout' => 3600, 'priority' => 50]);
        $this->audit->record('backup.requested', 'job', $jobId, null, null, ['type' => 'logical'], category: 'ops', severity: 'notice', actorId: $actor->id, actorName: $actor->username);
        $this->responder->success('Backup queued (job ' . substr($jobId, 0, 8) . '). It appears in the list as soon as a worker starts it.');
        return $this->responder->redirect('admin.backups');
    }

    public function verify(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor();
        $b = $this->backups->get((string) $route->getArgument('id'));
        $jobId = $this->queue->push('platform.backup.verify', ['backup_id' => $b['id'], 'initiated_by' => $actor->id], ['max_attempts' => 1, 'timeout' => 3600, 'idempotency_key' => 'backup-verify:' . $b['id'] . ':' . gmdate('YmdHi')]);
        $this->audit->record('backup.verify_requested', 'backup', (string) $b['id'], null, null, ['job_id' => $jobId], category: 'ops', actorId: $actor->id, actorName: $actor->username);
        $this->responder->success('Restore verification queued (job ' . substr($jobId, 0, 8) . ').');
        return $this->responder->redirect('admin.backups.view', ['id' => $b['id']]);
    }

    public function download(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor();
        $id = (string) $route->getArgument('id');
        $target = $this->backups->download($id);
        $this->audit->record(
            'backup.downloaded',
            'backup',
            $id,
            null,
            null,
            ['mode' => $target['type']],
            category: 'security',
            severity: 'warning',
            actorId: $actor->id,
            actorName: $actor->username,
        );
        if ($target['type'] === 'redirect') {
            return $this->responses->createResponse(302)->withHeader('Location', $target['url'])->withHeader('Cache-Control', 'no-store');
        }
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $target['filename']) . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStreamFromResource($target['stream']));
        return $target['size'] !== null ? $r->withHeader('Content-Length', (string) $target['size']) : $r;
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException();
        }
        return $identity->user;
    }
}
