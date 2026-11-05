<?php

declare(strict_types=1);

use App\Environment as Env;
use App\Platform\Redis\RedisInspector;
use App\Platform\Storage\UploadValidator;

/**
 * Runtime section services (Control Center 10–14): Redis inspector, upload validation.
 *
 * @var array $params
 */

return [
    RedisInspector::class => [
        '__construct()' => ['config' => $params['platform']['redis'], 'cacheConfig' => $params['platform']['redisCache']],
    ],
    UploadValidator::class => [
        // Hard upper bound is also enforced by php.ini (upload_max_filesize) and nginx (client_max_body_size).
        '__construct()' => ['maxBytes' => Env::int('UPLOAD_MAX_BYTES', 20 * 1024 * 1024)],
    ],
];
