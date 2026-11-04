<?php

declare(strict_types=1);

namespace App\Platform\Queue;

/**
 * A background job handler. Handlers MUST be idempotent: a job can be delivered more than once
 * (worker crash after side effect, timeout recovery). Use $context->jobId / idempotency keys.
 *
 * Register handlers in config/common/jobs.php: 'job.type' => HandlerClass::class.
 */
interface JobHandlerInterface
{
    /**
     * @param array<string,mixed> $payload
     * @throws PermanentJobFailure to fail without further retries
     * @throws \Throwable any other error → retried with exponential backoff until max_attempts
     */
    public function handle(array $payload, JobContext $context): void;
}
