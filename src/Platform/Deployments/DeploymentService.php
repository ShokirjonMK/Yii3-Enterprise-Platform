<?php

declare(strict_types=1);

namespace App\Platform\Deployments;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\OpsClients;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use Psr\Log\LoggerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * 16 · Deployment registry with four-eyes approval:
 *
 *   request (deploy.request) → approve | reject (deploy.approve, approver ≠ requester, step-up auth)
 *   approve → GitLab pipeline (DEPLOY_ENV, VERSION, DEPLOY_STRATEGY, DEPLOYMENT_ID) → running → succeeded | failed
 *   rollback = a new request for the previous successful version; when it succeeds the original
 *   deployment is marked rolled_back.
 *
 * The four-eyes rule is enforced here and by the DB constraint deployments_four_eyes.
 */
final class DeploymentService
{
    public const ENVIRONMENTS = ['dev', 'staging', 'production'];
    public const STRATEGIES = ['rolling', 'canary', 'bluegreen'];
    public const POLL_JOB = 'platform.deploy.poll';
    public const SORTABLE = ['created_at' => 'd.created_at', 'environment' => 'd.environment', 'version' => 'd.version', 'status' => 'd.status'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly OpsClients $clients,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function list(array $filters, int $page, ?string $sort, int $perPage = 20): Page
    {
        $q = $this->baseQuery();
        if (in_array($filters['environment'] ?? '', self::ENVIRONMENTS, true)) {
            $q->andWhere(['d.environment' => $filters['environment']]);
        }
        if (($filters['status'] ?? '') !== '') {
            $q->andWhere(['d.status' => (string) $filters['status']]);
        }
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-created_at');
    }

    public function get(string $id): array
    {
        if (!self::isUuid($id)) {
            throw new NotFoundException('Deployment not found.');
        }
        return $this->baseQuery()->where(['d.id' => $id])->one() ?? throw new NotFoundException('Deployment not found.');
    }

    /** @return array<string, array<string,mixed>|null> last succeeded deployment per environment */
    public function current(): array
    {
        $out = [];
        foreach (self::ENVIRONMENTS as $env) {
            $out[$env] = (new Query($this->db))->from('deployments')->where(['environment' => $env, 'status' => 'succeeded'])
                ->orderBy(['finished_at' => SORT_DESC, 'created_at' => SORT_DESC])->limit(1)->one();
        }
        return $out;
    }

    public function request(User $actor, array $input, ?string $rollbackOf = null): string
    {
        $this->access->assert($actor->id, 'deploy.request');
        $env = (string) ($input['environment'] ?? '');
        $version = trim((string) ($input['version'] ?? ''));
        $strategy = (string) ($input['strategy'] ?? 'rolling');
        $sha = trim((string) ($input['commit_sha'] ?? ''));
        $errors = [];
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            $errors['environment'][] = 'Choose an environment.';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $version) !== 1) {
            $errors['version'][] = 'Version must be a release tag or image tag (letters, digits, . _ -; max 64).';
        }
        if (!in_array($strategy, self::STRATEGIES, true)) {
            $errors['strategy'][] = 'Invalid strategy.';
        }
        if ($sha !== '' && preg_match('/^[0-9a-f]{7,64}$/', $sha) !== 1) {
            $errors['commit_sha'][] = 'Commit SHA must be 7–64 hex characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $open = (new Query($this->db))->from('deployments')->where(['environment' => $env, 'status' => ['requested', 'approved', 'running']])->exists();
        if ($open) {
            throw new ConflictException("Another deployment to $env is already pending or running.");
        }
        $row = [
            'environment' => $env,
            'version' => $version,
            'commit_sha' => $sha === '' ? null : $sha,
            'strategy' => $strategy,
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000),
            'requested_by' => $actor->id,
            'rollback_of' => $rollbackOf,
        ];
        return $this->db->transaction(function () use ($row, $actor): string {
            $id = (string) $this->db->createCommand()->insertReturningPks('deployments', $row)['id'];
            $this->audit->record(
                $row['rollback_of'] ? 'deploy.rollback_requested' : 'deploy.requested',
                'deployment',
                $id,
                null,
                $row,
                [],
                category: 'ops',
                severity: $row['environment'] === 'production' ? 'warning' : 'notice',
                actorId: $actor->id,
                actorName: $actor->username,
            );
            return $id;
        });
    }

    /**
     * Four-eyes approval, then the GitLab pipeline is triggered.
     *
     * @return array{triggered:bool, message:string}
     */
    public function approve(User $actor, string $id, string $comment = ''): array
    {
        $this->access->assert($actor->id, 'deploy.approve');
        // Checked outside the transaction so that the "denied" audit record is not rolled back
        $this->assertFourEyes($actor, $this->get($id), 'deploy.approve');
        $this->db->transaction(function () use ($actor, $id, $comment): void {
            $d = $this->lockRequested($id);
            $this->db->createCommand()->update('deployments', ['status' => 'approved', 'approved_by' => $actor->id, 'updated_at' => new Expression('now()')], ['id' => $id])->execute();
            $this->audit->record(
                'deploy.approved',
                'deployment',
                $id,
                ['status' => 'requested'],
                ['status' => 'approved'],
                ['environment' => $d['environment'], 'version' => $d['version'], 'comment' => mb_substr($comment, 0, 500)],
                category: 'ops',
                severity: 'warning',
                actorId: $actor->id,
                actorName: $actor->username,
            );
        });
        return $this->trigger($id, $actor);
    }

    public function reject(User $actor, string $id, string $reason): void
    {
        $this->access->assert($actor->id, 'deploy.approve');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'A reason is required.');
        }
        $this->assertFourEyes($actor, $this->get($id), 'deploy.reject');
        $this->db->transaction(function () use ($actor, $id, $reason): void {
            $d = $this->lockRequested($id);
            $this->db->createCommand()->update('deployments', [
                'status' => 'rejected', 'rejected_by' => $actor->id, 'error' => mb_substr($reason, 0, 1000),
                'updated_at' => new Expression('now()'), 'finished_at' => new Expression('now()'),
            ], ['id' => $id])->execute();
            $this->audit->record(
                'deploy.rejected',
                'deployment',
                $id,
                ['status' => 'requested'],
                ['status' => 'rejected'],
                ['environment' => $d['environment'], 'version' => $d['version'], 'reason' => mb_substr($reason, 0, 500)],
                category: 'ops',
                severity: 'notice',
                actorId: $actor->id,
                actorName: $actor->username,
            );
        });
    }

    /** Re-try the pipeline trigger of an approved deployment (e.g. after GitLab was configured). */
    public function trigger(string $id, ?User $actor = null): array
    {
        $d = $this->get($id);
        if ($d['status'] !== 'approved') {
            throw new ConflictException('Only approved deployments can be triggered.');
        }
        $gitlab = $this->clients->gitlab();
        if (!$gitlab->isConfigured()) {
            return ['triggered' => false, 'message' => 'GitLab is not configured — the deployment stays approved until a pipeline can be triggered.'];
        }
        try {
            $p = $gitlab->triggerPipeline($this->clients->gitlabRef(), [
                'DEPLOY_ENV' => (string) $d['environment'],
                'VERSION' => (string) $d['version'],
                'DEPLOY_STRATEGY' => (string) $d['strategy'],
                'DEPLOYMENT_ID' => (string) $d['id'],
            ]);
        } catch (ExternalServiceException $e) {
            $this->db->createCommand()->update('deployments', ['error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => new Expression('now()')], ['id' => $id])->execute();
            $this->audit->record(
                'deploy.pipeline_trigger',
                'deployment',
                $id,
                null,
                null,
                ['error' => $e->getMessage()],
                result: 'failure',
                category: 'ops',
                severity: 'warning',
                actorId: $actor?->id,
                actorName: $actor?->username,
            );
            return ['triggered' => false, 'message' => $e->getMessage()];
        }
        $this->db->createCommand()->update('deployments', [
            'status' => 'running', 'pipeline_id' => $p['id'], 'pipeline_url' => mb_substr($p['web_url'], 0, 1024),
            'commit_sha' => $d['commit_sha'] ?: ($p['sha'] !== '' ? $p['sha'] : null), 'error' => null, 'updated_at' => new Expression('now()'),
        ], ['id' => $id])->execute();
        $this->audit->record(
            'deploy.pipeline_triggered',
            'deployment',
            $id,
            ['status' => 'approved'],
            ['status' => 'running'],
            ['pipeline_id' => $p['id'], 'pipeline_url' => $p['web_url'], 'ref' => $p['ref']],
            category: 'ops',
            severity: 'notice',
            actorId: $actor?->id,
            actorName: $actor?->username,
        );
        $this->schedulePoll($id, 0);
        return ['triggered' => true, 'message' => 'Pipeline #' . $p['id'] . ' started.'];
    }

    /** Polls GitLab and updates the deployment status. Returns the (new) status. */
    public function refresh(string $id, ?User $actor = null): string
    {
        $d = $this->get($id);
        if ($d['status'] !== 'running' || $d['pipeline_id'] === null) {
            return (string) $d['status'];
        }
        $p = $this->clients->gitlab()->pipeline((int) $d['pipeline_id']);
        $status = GitLabClient::mapStatus($p['status']);
        if ($status === 'running') {
            return 'running';
        }
        $this->db->transaction(function () use ($d, $status, $p, $actor): void {
            $this->db->createCommand()->update('deployments', [
                'status' => $status, 'finished_at' => new Expression('now()'), 'updated_at' => new Expression('now()'),
                'error' => $status === 'failed' ? 'Pipeline ' . $p['status'] : null,
            ], ['id' => $d['id'], 'status' => 'running'])->execute();
            $this->audit->record(
                'deploy.' . $status,
                'deployment',
                (string) $d['id'],
                ['status' => 'running'],
                ['status' => $status],
                ['pipeline_id' => $p['id'], 'pipeline_status' => $p['status'], 'environment' => $d['environment'], 'version' => $d['version']],
                result: $status === 'succeeded' ? 'success' : 'failure',
                category: 'ops',
                severity: $status === 'succeeded' ? 'notice' : 'warning',
                actorId: $actor?->id,
                actorName: $actor?->username,
            );
            if ($status === 'succeeded' && $d['rollback_of'] !== null) {
                $n = $this->db->createCommand()->update(
                    'deployments',
                    ['status' => 'rolled_back', 'updated_at' => new Expression('now()')],
                    ['id' => $d['rollback_of'], 'status' => 'succeeded'],
                )->execute();
                if ($n > 0) {
                    $this->audit->record(
                        'deploy.rolled_back',
                        'deployment',
                        (string) $d['rollback_of'],
                        ['status' => 'succeeded'],
                        ['status' => 'rolled_back'],
                        ['by_deployment' => $d['id'], 'version' => $d['version']],
                        category: 'ops',
                        severity: 'warning',
                        actorId: $actor?->id,
                        actorName: $actor?->username,
                    );
                }
            }
        });
        return $status;
    }

    /**
     * Requests a rollback of a successful deployment: a new deployment of the previous successful
     * version in the same environment (goes through the same four-eyes approval).
     */
    public function requestRollback(User $actor, string $id, string $notes = ''): string
    {
        $this->access->assert($actor->id, 'deploy.request');
        $d = $this->get($id);
        if ($d['status'] !== 'succeeded') {
            throw new ConflictException('Only a successful deployment can be rolled back.');
        }
        $previous = (new Query($this->db))->from('deployments')
            ->where(['environment' => $d['environment'], 'status' => ['succeeded', 'rolled_back']])
            ->andWhere(['<', 'created_at', $d['created_at']])
            ->andWhere(['<>', 'version', $d['version']])
            ->orderBy(['created_at' => SORT_DESC])->limit(1)->one();
        if ($previous === null) {
            throw new ConflictException('There is no earlier successful version to roll back to.');
        }
        return $this->request($actor, [
            'environment' => $d['environment'],
            'version' => $previous['version'],
            'commit_sha' => $previous['commit_sha'] ?? '',
            'strategy' => $d['strategy'],
            'notes' => trim('Rollback of ' . $d['version'] . ' → ' . $previous['version'] . '. ' . $notes),
        ], (string) $d['id']);
    }

    /** @return array{configured:bool, pipelines:list<array>, error:?string, url:string, project:string} */
    public function pipelines(int $limit = 10): array
    {
        $gitlab = $this->clients->gitlab();
        $out = ['configured' => $gitlab->isConfigured(), 'pipelines' => [], 'error' => null, 'url' => $gitlab->baseUrl(), 'project' => $gitlab->projectId()];
        if ($gitlab->isConfigured()) {
            try {
                $out['pipelines'] = $gitlab->recentPipelines($limit);
            } catch (ExternalServiceException $e) {
                $out['error'] = $e->getMessage();
            }
        }
        return $out;
    }

    public function schedulePoll(string $id, int $attempt): void
    {
        try {
            $this->queue->push(self::POLL_JOB, ['deployment_id' => $id, 'attempt' => $attempt], [
                'delay' => 30, 'max_attempts' => 3, 'idempotency_key' => 'deploy-poll:' . $id . ':' . $attempt,
            ]);
        } catch (\Throwable $e) {
            // Polling is a convenience; operators can always refresh manually
            $this->logger->warning('Deployment poll could not be scheduled', ['deployment' => $id, 'exception' => $e->getMessage()]);
        }
    }

    private function lockRequested(string $id): array
    {
        if (!self::isUuid($id)) {
            throw new NotFoundException('Deployment not found.');
        }
        $d = $this->db->createCommand('SELECT * FROM deployments WHERE id = :id FOR UPDATE', [':id' => $id])->queryOne();
        if ($d === null || $d === false) {
            throw new NotFoundException('Deployment not found.');
        }
        if ($d['status'] !== 'requested') {
            throw new ConflictException('This deployment has already been decided.');
        }
        return $d;
    }

    private function assertFourEyes(User $actor, array $d, string $action): void
    {
        if ((string) $d['requested_by'] === $actor->id) {
            $this->audit->record(
                $action,
                'deployment',
                (string) $d['id'],
                null,
                null,
                ['rule' => 'four-eyes'],
                result: 'denied',
                category: 'security',
                severity: 'warning',
                actorId: $actor->id,
                actorName: $actor->username,
            );
            throw new AccessDeniedException('Four-eyes rule: a deployment must be decided by someone other than the requester.');
        }
    }

    private function baseQuery(): Query
    {
        return (new Query($this->db))
            ->select(['d.*', 'requester' => 'ru.username', 'approver' => 'au.username', 'rejecter' => 'xu.username'])
            ->from(['d' => 'deployments'])
            ->leftJoin(['ru' => 'users'], 'ru.id = d.requested_by')
            ->leftJoin(['au' => 'users'], 'au.id = d.approved_by')
            ->leftJoin(['xu' => 'users'], 'xu.id = d.rejected_by');
    }

    private static function isUuid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }
}
