<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use RuntimeException;

final class JobTimeout extends RuntimeException {}
