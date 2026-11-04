<?php

declare(strict_types=1);

use App\Platform\Notifications\Channel;

/** Integration work package: external notification channels. */
return [
    'telegram' => Channel\TelegramChannel::class,
    'sms' => Channel\SmsChannel::class,
    'webpush' => Channel\WebPushChannel::class,
];
