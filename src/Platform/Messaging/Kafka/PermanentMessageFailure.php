<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RuntimeException;

/**
 * Thrown by a handler when retrying cannot help (invalid payload, unknown recipient …):
 * the message goes to the dead-letter queue immediately, without retries.
 *
 * Author: ShokirjonMK
 */
final class PermanentMessageFailure extends RuntimeException
{
}
