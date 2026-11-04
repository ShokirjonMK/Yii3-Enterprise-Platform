<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Platform\Monitoring\Metrics;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Delivers one queued notification through its channel adapter. Idempotent: already-sent rows are skipped.
 */
final class DeliverNotificationJob implements JobHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ChannelRegistry $channels,
        private readonly Metrics $metrics,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $n = (new Query($this->db))->from('notifications')->where(['id' => (string) ($payload['notification_id'] ?? '')])->one();
        if ($n === null) {
            throw new PermanentJobFailure('Notification not found.');
        }
        if (in_array($n['status'], ['sent', 'delivered', 'read', 'skipped'], true)) {
            return;
        }
        $this->db->createCommand()->update('notifications', ['status' => 'sending', 'attempts' => new Expression('attempts + 1')], ['id' => $n['id']])->execute();
        try {
            $providerId = $this->channels->get((string) $n['channel'])->send($n);
            $this->db->createCommand()->update('notifications', [
                'status' => 'sent', 'sent_at' => new Expression('now()'), 'provider_message_id' => $providerId, 'last_error' => null,
            ], ['id' => $n['id']])->execute();
            $this->metrics->inc('notifications_total', 'Notification deliveries', ['channel' => $n['channel'], 'result' => 'sent']);
        } catch (Throwable $e) {
            $this->db->createCommand()->update('notifications', ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $n['id']])->execute();
            $this->metrics->inc('notifications_total', 'Notification deliveries', ['channel' => $n['channel'], 'result' => 'failed']);
            throw $e;
        }
    }
}
