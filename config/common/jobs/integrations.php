<?php

declare(strict_types=1);

/** Integration work package job handlers. */
return [
    'platform.webhooks.ack' => ['handler' => App\Platform\Integrations\Webhook\WebhookAckJob::class, 'queue' => 'default'],
    'platform.approvals.expire' => App\Platform\Workflow\ExpireApprovalsJob::class,
    'platform.approvals.notify' => ['handler' => App\Platform\Workflow\NotifyApproversJob::class, 'queue' => 'notifications'],
];
