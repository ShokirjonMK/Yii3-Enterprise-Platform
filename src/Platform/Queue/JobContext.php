<?php

declare(strict_types=1);

namespace App\Platform\Queue;

/**
 * Execution context passed to handlers: identity of the job, cooperative cancellation and progress.
 */
final class JobContext
{
    /** @param \Closure(): bool $isCancelled @param \Closure(int): void $reportProgress */
    public function __construct(
        public readonly string $jobId,
        public readonly string $type,
        public readonly int $attempt,
        public readonly ?string $correlationId,
        private readonly \Closure $isCancelled,
        private readonly \Closure $reportProgress,
    ) {}

    /** Long-running handlers should poll this and stop early (throw JobCancelled). */
    public function isCancelled(): bool
    {
        return ($this->isCancelled)();
    }

    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new JobCancelled('Job cancelled by operator.');
        }
    }

    public function progress(int $percent): void
    {
        ($this->reportProgress)(max(0, min(100, $percent)));
    }
}
