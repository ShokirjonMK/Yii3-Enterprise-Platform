<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Webhook;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;
use Psr\Log\LoggerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

/**
 * Default handler for webhook endpoints without a module-specific job type ("platform.webhooks.ack"):
 * acknowledges the stored event (processed_at) and writes a structured log line. Business modules register
 * their own job type (config/common/jobs/<module>.php) and select it on the endpoint; their handler receives
 * {webhook_event_id, endpoint, external_id} and should set webhook_events.processed_at itself.
 * Idempotent: an already processed event is skipped.
 */
final class WebhookAckJob implements JobHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = (int) ($payload['webhook_event_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentJobFailure('webhook_event_id is required.');
        }
        $n = $this->db->createCommand()->update('webhook_events', ['processed_at' => new Expression('now()')], ['id' => $id, 'processed_at' => null])->execute();
        $this->logger->info('Webhook event acknowledged', ['webhook_event_id' => $id, 'endpoint' => $payload['endpoint'] ?? null, 'changed' => $n]);
    }
}
