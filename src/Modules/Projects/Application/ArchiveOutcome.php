<?php

declare(strict_types=1);

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;

/** Result of an archive request: archived immediately, or waiting for an approval decision. */
final readonly class ArchiveOutcome
{
    public function __construct(
        public Project $project,
        public ?string $approvalId = null,
    ) {}

    public function isPending(): bool
    {
        return $this->approvalId !== null;
    }
}
