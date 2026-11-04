<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use RuntimeException;

/** Non-retryable failure (invalid payload, business rule) — the job goes straight to "dead". */
final class PermanentJobFailure extends RuntimeException
{
}
