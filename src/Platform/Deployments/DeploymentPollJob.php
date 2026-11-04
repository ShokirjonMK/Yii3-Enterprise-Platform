<?php

declare(strict_types=1);

namespace App\Platform\Deployments;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;

/**
 * `platform.deploy.poll` — polls the GitLab pipeline of a running deployment every 30 s
 * (up to 2 hours) and records the final status. Idempotent: a finished deployment is a no-op.
 */
final class DeploymentPollJob implements JobHandlerInterface
{
    public const MAX_POLLS = 240;

    public function __construct(private readonly DeploymentService $deployments) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = (string) ($payload['deployment_id'] ?? '');
        if ($id === '') {
            throw new PermanentJobFailure('deployment_id is required.');
        }
        $attempt = (int) ($payload['attempt'] ?? 0);
        $status = $this->deployments->refresh($id);
        if ($status === 'running' && $attempt + 1 < self::MAX_POLLS) {
            $this->deployments->schedulePoll($id, $attempt + 1);
        }
    }
}
