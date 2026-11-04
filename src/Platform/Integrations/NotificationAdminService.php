<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\ChannelRegistry;
use App\Platform\Notifications\Notifier;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Application service behind Admin → 08 Messaging & Notifications: channel administration (enable/disable,
 * honest health from the adapters), templates CRUD + preview, delivery retry and role broadcasts.
 * Delivery itself stays in the platform core ({@see Notifier}, DeliverNotificationJob).
 */
final class NotificationAdminService
{
    public const LOCALES = ['uz', 'ru', 'en'];
    public const MAX_BROADCAST = 10000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ChannelRegistry $channels,
        private readonly Notifier $notifier,
        private readonly JobQueue $queue,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly RbacCatalog $catalog,
    ) {}

    /** @return list<array<string,mixed>> */
    public function channels(): array
    {
        $map = $this->channels->all();
        $rows = (new Query($this->db))->from('notification_channels')->orderBy(['code' => SORT_ASC])->all();
        $stats = (new Query($this->db))->select(['channel', 'status', 'n' => new Expression('count(*)')])->from('notifications')
            ->where(['>', 'created_at', new Expression("now() - interval '24 hours'")])->groupBy(['channel', 'status'])->all();
        $by = [];
        foreach ($stats as $s) {
            $by[$s['channel']][$s['status']] = (int) $s['n'];
        }
        foreach ($rows as &$r) {
            $r['adapter'] = isset($map[$r['code']]);
            $r['stats'] = $by[$r['code']] ?? [];
        }
        return $rows;
    }

    public function channel(string $code): array
    {
        return (new Query($this->db))->from('notification_channels')->where(['code' => $code])->one() ?? throw new NotFoundException('Channel not found.');
    }

    /** Runs the adapter health check and stores the result. @return array{status:string, details:array} */
    public function checkHealth(string $actorId, string $code): array
    {
        $this->access->assert($actorId, 'notifications.view');
        $this->channel($code);
        try {
            $health = $this->channels->get($code)->health();
        } catch (Throwable $e) {
            $health = ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
        $status = in_array($health['status'], ['healthy', 'degraded', 'failed', 'unconfigured', 'maintenance'], true) ? $health['status'] : 'failed';
        $error = $status === 'healthy' ? null : (string) ($health['details']['error'] ?? (isset($health['details']['missing']) ? 'missing ' . $health['details']['missing'] : ''));
        $this->db->createCommand()->update('notification_channels', ['status' => $status, 'last_error' => $error ?: null, 'updated_at' => new Expression('now()')], ['code' => $code])->execute();
        return ['status' => $status, 'details' => $health['details']];
    }

    public function setEnabled(string $actorId, string $code, bool $enabled): void
    {
        $this->access->assert($actorId, 'notifications.manage');
        $row = $this->channel($code);
        if ($code === 'inapp' && !$enabled) {
            throw new ConflictException('The in-app channel is the delivery fallback and cannot be disabled.', 'inapp_required');
        }
        $status = $row['status'];
        if ($enabled) {
            $health = $this->checkHealth($actorId, $code);
            if ($health['status'] === 'unconfigured') {
                throw ValidationException::single('channel', 'The channel is not configured yet (' . ($health['details']['missing'] ?? 'see System Settings → Notifications') . ').');
            }
            $status = $health['status'];
        }
        $this->db->transaction(function () use ($code, $enabled, $actorId, $row, $status): void {
            $this->db->createCommand()->update('notification_channels', ['enabled' => $enabled, 'status' => $status, 'updated_at' => new Expression('now()')], ['code' => $code])->execute();
            $this->audit->record($enabled ? 'notification_channel.enabled' : 'notification_channel.disabled', 'notification_channel', $code,
                ['enabled' => (bool) $row['enabled']], ['enabled' => $enabled], category: 'config', severity: 'notice', actorId: $actorId);
        });
    }

    /** Sends a test notification to the acting admin through one channel. @return array<string,mixed> notification row */
    public function testSend(string $actorId, string $code): array
    {
        $this->access->assert($actorId, 'notifications.manage');
        $this->channel($code);
        $ids = $this->notifier->notify($actorId, 'platform.test', [
            'message' => 'Test notification from the Platform Control Center (' . date('Y-m-d H:i:s') . ').',
        ], [$code], 'system');
        if ($ids === []) {
            throw new ConflictException('Your account cannot receive notifications (inactive user).', 'no_recipient');
        }
        $this->audit->record('notification_channel.test_sent', 'notification_channel', $code, null, null, ['notification_id' => $ids[0]], actorId: $actorId);
        return (new Query($this->db))->from('notifications')->where(['id' => $ids[0]])->one() ?? [];
    }

    // ------------------------------------------------------------------------------------------ templates

    public function templatesQuery(): Query
    {
        return (new Query($this->db))->select(['t.*', 'u.username AS updated_by_name'])->from(['t' => 'notification_templates'])->leftJoin(['u' => 'users'], 'u.id = t.updated_by');
    }

    public function template(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->templatesQuery()->where(['t.id' => $id])->one() : null;
        return $row ?? throw new NotFoundException('Template not found.');
    }

    /** @param array<string,mixed> $in */
    public function saveTemplate(string $actorId, ?string $id, array $in): string
    {
        $this->access->assert($actorId, 'notifications.manage');
        $before = $id !== null ? $this->template($id) : null;
        $data = $this->validateTemplate($in);
        $dup = (new Query($this->db))->from('notification_templates')->where(['code' => $data['code'], 'channel' => $data['channel'], 'locale' => $data['locale']]);
        if ($id !== null) {
            $dup->andWhere(['<>', 'id', $id]);
        }
        if ($dup->exists()) {
            throw ValidationException::single('code', 'A template for this code, channel and locale already exists.');
        }
        $id ??= Uuid::v7();
        $this->db->transaction(function () use ($id, $before, $data, $actorId): void {
            if ($before === null) {
                $this->db->createCommand()->insert('notification_templates', ['id' => $id, 'updated_by' => $actorId] + $data)->execute();
            } else {
                $this->db->createCommand()->update('notification_templates', $data + ['version' => new Expression('version + 1'), 'updated_by' => $actorId, 'updated_at' => new Expression('now()')], ['id' => $id])->execute();
            }
            $this->audit->record($before === null ? 'notification_template.created' : 'notification_template.updated', 'notification_template', $id,
                $before === null ? null : array_intersect_key($before, $data), $data, category: 'config', actorId: $actorId);
        });
        return $id;
    }

    public function deleteTemplate(string $actorId, string $id): void
    {
        $this->access->assert($actorId, 'notifications.manage');
        $row = $this->template($id);
        $this->db->transaction(function () use ($row, $actorId): void {
            $this->db->createCommand()->delete('notification_templates', ['id' => $row['id']])->execute();
            $this->audit->record('notification_template.deleted', 'notification_template', $row['id'], ['code' => $row['code'], 'channel' => $row['channel'], 'locale' => $row['locale']], null, category: 'config', actorId: $actorId);
        });
    }

    /**
     * Preview: the draft (subject/body given) uses the same "{placeholder}" substitution as Notifier; without a draft
     * the effective template is resolved by Notifier::render (channel/locale fallback chain).
     *
     * @param array<string,scalar|null> $params
     * @return array{subject:string, body:string, source:string}
     */
    public function preview(string $code, string $channel, string $locale, array $params, ?string $subject = null, ?string $body = null): array
    {
        if ($body !== null && $body !== '') {
            $replace = [];
            foreach ($params as $k => $v) {
                $replace['{' . $k . '}'] = (string) $v;
            }
            return ['subject' => strtr((string) $subject, $replace), 'body' => strtr($body, $replace), 'source' => 'draft'];
        }
        [$s, $b] = $this->notifier->render($code, $channel, $locale, $params);
        return ['subject' => $s, 'body' => $b, 'source' => 'stored'];
    }

    // ------------------------------------------------------------------------------------------ deliveries

    public function deliveriesQuery(): Query
    {
        return (new Query($this->db))->select(['n.id', 'n.created_at', 'n.channel', 'n.status', 'n.template_code', 'n.recipient', 'n.subject', 'n.attempts', 'n.last_error', 'n.sent_at', 'n.category', 'n.user_id', 'u.username'])
            ->from(['n' => 'notifications'])->leftJoin(['u' => 'users'], 'u.id = n.user_id');
    }

    public function delivery(string $id): array
    {
        $row = Uuid::isValid($id) ? (new Query($this->db))->select(['n.*', 'u.username'])->from(['n' => 'notifications'])->leftJoin(['u' => 'users'], 'u.id = n.user_id')->where(['n.id' => $id])->one() : null;
        return $row ?? throw new NotFoundException('Delivery not found.');
    }

    /** Re-enqueues a failed delivery (platform.notifications.deliver). */
    public function retry(string $actorId, string $id): string
    {
        $this->access->assert($actorId, 'notifications.manage');
        $n = $this->delivery($id);
        if ($n['status'] !== 'failed') {
            throw new ConflictException('Only failed deliveries can be retried.', 'not_failed');
        }
        return $this->db->transaction(function () use ($n, $actorId): string {
            $this->db->createCommand()->update('notifications', ['status' => 'queued', 'last_error' => null], ['id' => $n['id'], 'status' => 'failed'])->execute();
            $jobId = $this->queue->push(Notifier::DELIVER_JOB, ['notification_id' => $n['id']], [
                'queue' => 'notifications', 'max_attempts' => 6, 'backoff' => 30, 'idempotency_key' => 'notify:' . $n['id'] . ':retry:' . (int) $n['attempts'] . ':' . time(),
            ]);
            $this->db->createCommand()->update('notifications', ['job_id' => $jobId], ['id' => $n['id']])->execute();
            $this->audit->record('notification.retried', 'notification', $n['id'], ['status' => 'failed'], ['status' => 'queued'], ['job_id' => $jobId, 'channel' => $n['channel']], actorId: $actorId);
            return $jobId;
        });
    }

    /** @return int number of re-enqueued deliveries (max 500 per call) */
    public function retryFailed(string $actorId, ?string $channel = null): int
    {
        $this->access->assert($actorId, 'notifications.manage');
        $q = (new Query($this->db))->select('id')->from('notifications')->where(['status' => 'failed'])->orderBy(['created_at' => SORT_DESC])->limit(500);
        if ($channel !== null && $channel !== '') {
            $q->andWhere(['channel' => $channel]);
        }
        $n = 0;
        foreach ($q->column() as $id) {
            try {
                $this->retry($actorId, (string) $id);
                $n++;
            } catch (ConflictException) {
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------------------------------ broadcast

    /**
     * Sends a notification to every active user holding $role (directly or through a parent role).
     *
     * @param list<string> $channels
     * @return array{users:int, notifications:int}
     */
    public function broadcast(string $actorId, string $role, string $templateCode, string $subject, string $message, array $channels, string $category = 'broadcast'): array
    {
        $this->access->assert($actorId, 'notifications.send');
        $roles = $this->catalog->roles();
        $e = [];
        if (!isset($roles[$role])) {
            $e['role'][] = 'Unknown role.';
        }
        $channels = array_values(array_intersect($channels, ['inapp', 'email', 'telegram', 'sms', 'webpush']));
        if ($channels === []) {
            $e['channels'][] = 'Select at least one channel.';
        }
        $templateCode = trim($templateCode);
        $message = trim($message);
        if ($templateCode === '' && $message === '') {
            $e['message'][] = 'Enter a message or choose a template.';
        }
        if ($templateCode !== '' && preg_match('/^[a-z0-9][a-z0-9_.-]{1,95}$/', $templateCode) !== 1) {
            $e['template_code'][] = 'Invalid template code.';
        }
        if (mb_strlen($message) > 5000 || mb_strlen($subject) > 200) {
            $e['message'][] = 'Message too long (5000 characters, subject 200).';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $holders = $this->rolesIncluding($role, $roles);
        $q = (new Query($this->db))->select('u.id')->distinct()->from(['a' => 'yii_rbac_assignment'])
            ->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->where(['a.item_name' => $holders, 'u.status' => 'active', 'u.deleted_at' => null])->limit(self::MAX_BROADCAST);
        if (!$this->access->can($actorId, 'tenants.cross')) {
            $tenant = (new Query($this->db))->select('tenant_id')->from('users')->where(['id' => $actorId])->scalar();
            $q->andWhere(['u.tenant_id' => $tenant ?: null]);
        }
        $userIds = $q->column();
        $code = $templateCode !== '' ? $templateCode : 'platform.broadcast';
        $params = ['message' => $message, 'subject' => $subject];
        $count = 0;
        foreach ($userIds as $uid) {
            $count += count($this->notifier->notify((string) $uid, $code, $params, $channels, $category));
        }
        $this->audit->record('notifications.broadcast', 'role', $role, null, null,
            ['template' => $code, 'channels' => $channels, 'users' => count($userIds), 'notifications' => $count], category: 'data', severity: 'notice', actorId: $actorId);
        return ['users' => count($userIds), 'notifications' => $count];
    }

    /** @return list<string> roles whose effective role set contains $role */
    private function rolesIncluding(string $role, array $roles): array
    {
        $out = [];
        foreach (array_keys($roles) as $r) {
            $stack = [$r];
            $seen = [];
            while ($stack !== []) {
                $c = array_pop($stack);
                if (isset($seen[$c])) {
                    continue;
                }
                $seen[$c] = true;
                if ($c === $role) {
                    $out[] = $r;
                    break;
                }
                foreach ($roles[$c]['children'] ?? [] as $child) {
                    $stack[] = $child;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function validateTemplate(array $in): array
    {
        $e = [];
        $code = trim((string) ($in['code'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,95}$/', $code) !== 1) {
            $e['code'][] = 'Lowercase identifier, e.g. "project.status_changed".';
        }
        $channel = (string) ($in['channel'] ?? '');
        if (!(new Query($this->db))->from('notification_channels')->where(['code' => $channel])->exists()) {
            $e['channel'][] = 'Unknown channel.';
        }
        $locale = (string) ($in['locale'] ?? '');
        if (!in_array($locale, self::LOCALES, true)) {
            $e['locale'][] = 'Unsupported locale.';
        }
        $subject = trim((string) ($in['subject'] ?? ''));
        if (mb_strlen($subject) > 255) {
            $e['subject'][] = 'Maximum 255 characters.';
        }
        $body = (string) ($in['body'] ?? '');
        if (trim($body) === '' || mb_strlen($body) > 20000) {
            $e['body'][] = 'Body is required (max 20000 characters).';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return ['code' => $code, 'channel' => $channel, 'locale' => $locale, 'subject' => $subject, 'body' => $body];
    }
}
