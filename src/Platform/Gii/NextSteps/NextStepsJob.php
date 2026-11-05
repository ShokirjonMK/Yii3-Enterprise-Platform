<?php

declare(strict_types=1);

namespace App\Platform\Gii\NextSteps;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;

/**
 * `devtools.gii.next_steps` — executes a run started in Admin → Developer tools → Enterprise Gii → Next steps.
 * One attempt only (migrations are not retried blindly); the run row carries status and log.
 *
 * Author: ShokirjonMK
 */
final class NextStepsJob implements JobHandlerInterface
{
    public function __construct(private readonly NextStepsRunner $runner) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = (string) ($payload['run_id'] ?? '');
        if ($id === '') {
            throw new PermanentJobFailure('run_id is required.');
        }
        $this->runner->execute($id);
    }
}
