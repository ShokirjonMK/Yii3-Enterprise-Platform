<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Kafka;

use App\Platform\Messaging\Kafka\Attribute\KafkaChannel;
use App\Platform\Messaging\Kafka\KafkaMessage;
use App\Platform\Messaging\Kafka\KafkaMessageHandlerInterface;
use App\Platform\Messaging\Kafka\PermanentMessageFailure;
use App\Platform\Notifications\Notifier;
use App\Shared\Domain\Uuid;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * External systems send notifications to platform users through Kafka:
 *
 *   ./yii kafka:publish platform.notifications.inbound \
 *     '{"user":"admin","subject":"Invoice paid","message":"Invoice #42 was paid","channels":["inapp","email"]}'
 *
 * `user` = user id, username or e-mail. Channels default to in-app; disabled channels and user opt-outs are
 * respected by the Notifier. Invalid messages go straight to the dead-letter queue (no retries);
 * redeliveries are de-duplicated by the inbox (event_id header).
 *
 * Author: ShokirjonMK
 */
#[KafkaChannel(topic: 'platform.notifications.inbound', group: 'notifications-inbound', description: 'Notifications to users from external systems')]
final class InboundNotificationHandler implements KafkaMessageHandlerInterface
{
    private const CHANNELS = ['inapp', 'email', 'telegram', 'sms', 'webpush'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Notifier $notifier,
    ) {}

    public function handle(array $payload, KafkaMessage $message): void
    {
        $user = trim((string) ($payload['user'] ?? ''));
        $text = trim((string) ($payload['message'] ?? ''));
        if ($user === '' || $text === '') {
            throw new PermanentMessageFailure('"user" and "message" are required.');
        }
        $channels = array_values(array_intersect((array) ($payload['channels'] ?? ['inapp']), self::CHANNELS));
        if ($channels === []) {
            throw new PermanentMessageFailure('No valid channel (allowed: ' . implode(', ', self::CHANNELS) . ').');
        }
        $id = (new Query($this->db))->select('id')->from('users')
            ->where(['deleted_at' => null])
            ->andWhere(Uuid::isValid($user) ? ['id' => $user] : ['or', ['username' => $user], new Expression('lower(email) = :email', [':email' => mb_strtolower($user)])])
            ->scalar();
        if ($id === false || $id === null) {
            throw new PermanentMessageFailure('Unknown user.');
        }
        $this->notifier->notify((string) $id, 'external.message', [
            'subject' => mb_substr(trim((string) ($payload['subject'] ?? '')) ?: 'Notification', 0, 200),
            'message' => mb_substr($text, 0, 4000),
            'source' => mb_substr((string) ($message->headers['source'] ?? 'kafka'), 0, 60),
        ], $channels, mb_substr((string) ($payload['category'] ?? 'integration'), 0, 40));
    }
}
