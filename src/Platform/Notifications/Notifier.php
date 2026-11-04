<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Uuid;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Application entry point for user notifications.
 *
 *   $notifier->notify($userId, 'project.status_changed', ['project' => 'X', 'status' => 'active'], ['inapp', 'email']);
 *
 * - templates are resolved per channel and the user's locale (fallback: en, then the code itself),
 * - user preferences (notification_preferences) can opt out of a category per channel,
 * - in-app notifications are stored immediately; other channels are delivered asynchronously by the
 *   `platform.notifications.deliver` job (retry with backoff, failure tracking in `notifications`).
 */
final class Notifier
{
    public const DELIVER_JOB = 'platform.notifications.deliver';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly JobQueue $queue,
    ) {}

    /**
     * @param array<string,scalar|null> $params
     * @param list<string> $channels
     * @return list<string> created notification ids
     */
    public function notify(string $userId, string $templateCode, array $params = [], array $channels = ['inapp'], string $category = 'system'): array
    {
        $user = (new Query($this->db))->select(['id', 'email', 'phone', 'locale', 'tenant_id', 'full_name', 'username', 'status'])
            ->from('users')->where(['id' => $userId, 'deleted_at' => null])->one();
        if ($user === null || $user['status'] !== 'active') {
            return [];
        }
        $params += ['name' => $user['full_name'] ?: $user['username']];
        $optOut = (new Query($this->db))->select('channel')->from('notification_preferences')
            ->where(['user_id' => $userId, 'category' => $category, 'enabled' => false])->column();
        $enabled = (new Query($this->db))->select('code')->from('notification_channels')->where(['enabled' => true])->column();

        $ids = [];
        foreach (array_unique($channels) as $channel) {
            if (in_array($channel, $optOut, true)) {
                continue;
            }
            $recipient = match ($channel) {
                'email' => $user['email'],
                'sms' => $user['phone'],
                'telegram' => $this->telegramChatId($userId),
                default => $userId,
            };
            [$subject, $body] = $this->render($templateCode, $channel, (string) $user['locale'], $params);
            $id = Uuid::v7();
            $status = match (true) {
                $recipient === null || $recipient === '' => 'skipped',
                !in_array($channel, $enabled, true) => 'skipped',
                $channel === 'inapp' => 'delivered',
                default => 'queued',
            };
            $this->db->createCommand()->insert('notifications', [
                'id' => $id,
                'user_id' => $userId,
                'tenant_id' => $user['tenant_id'],
                'category' => $category,
                'template_code' => $templateCode,
                'channel' => $channel,
                'recipient' => (string) ($recipient ?? ''),
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'status' => $status,
                'last_error' => $status === 'skipped' ? ($recipient ? 'channel disabled' : 'no recipient address') : null,
                'sent_at' => $status === 'delivered' ? date(DATE_ATOM) : null,
            ])->execute();
            if ($status === 'queued') {
                $jobId = $this->queue->push(self::DELIVER_JOB, ['notification_id' => $id], ['queue' => 'notifications', 'max_attempts' => 6, 'backoff' => 30, 'idempotency_key' => 'notify:' . $id]);
                $this->db->createCommand()->update('notifications', ['job_id' => $jobId], ['id' => $id])->execute();
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** @return array{0:string,1:string} */
    public function render(string $code, string $channel, string $locale, array $params): array
    {
        $tpl = null;
        foreach ([[$channel, $locale], [$channel, 'en'], ['inapp', $locale], ['inapp', 'en']] as [$ch, $loc]) {
            $tpl = (new Query($this->db))->select(['subject', 'body'])->from('notification_templates')
                ->where(['code' => $code, 'channel' => $ch, 'locale' => $loc])->one();
            if ($tpl !== null) {
                break;
            }
        }
        $tpl ??= ['subject' => $code, 'body' => (string) ($params['message'] ?? $code)];
        $replace = [];
        foreach ($params as $k => $v) {
            $replace['{' . $k . '}'] = (string) $v;
        }
        return [strtr((string) $tpl['subject'], $replace), strtr((string) $tpl['body'], $replace)];
    }

    private function telegramChatId(string $userId): ?string
    {
        $v = (new Query($this->db))->select('value')->from('settings')->where(['key' => 'notifications.telegram_chat_id', 'scope' => 'user', 'scope_id' => $userId])->scalar();
        return $v === false || $v === null ? null : trim((string) json_decode((string) $v, true));
    }
}
