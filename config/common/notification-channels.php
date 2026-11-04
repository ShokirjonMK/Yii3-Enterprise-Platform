<?php

declare(strict_types=1);

use App\Platform\Notifications\Channel;

/**
 * Notification channel adapters. Additional adapters: config/common/notification-channels/*.php
 */
$map = [
    'inapp' => Channel\InAppChannel::class,
    'email' => Channel\EmailChannel::class,
];

foreach (glob(__DIR__ . '/notification-channels/*.php') ?: [] as $file) {
    $map = array_merge($map, require $file);
}

return $map;
