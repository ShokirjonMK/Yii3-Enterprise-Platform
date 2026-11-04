<?php

declare(strict_types=1);

namespace App\Platform\Workflow;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;

/** "platform.approvals.expire" — scheduled every 5 minutes (schedule seeded by M261104040002WorkflowSeed). */
final class ExpireApprovalsJob implements JobHandlerInterface
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->approvals->expireOverdue();
    }
}
