<?php

declare(strict_types=1);

namespace App\Platform\Setup;

use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Idempotent reference data: default tenant, feature flags, system schedules, Kafka topic registry,
 * notification templates. Existing rows are never overwritten (admins may have changed them).
 */
final class PlatformSeeder
{
    public const DEFAULT_TENANT = 'default';

    public const FLAGS = [
        ['auth.local_login', 'Local login (username/password)', 'Allows password sign-in. Accounts with policy local_only (break-glass) are always allowed.', true, false, []],
        ['auth.sso', 'Single sign-on', 'Enables OneID / corporate OIDC providers on the login page.', true, false, []],
        ['security.mfa_enforcement', 'MFA enforcement for privileged roles', 'Critical control — cannot be disabled through feature flags.', true, true, []],
        ['security.audit', 'Audit logging', 'Critical control — cannot be disabled through feature flags.', true, true, []],
        ['api.v1', 'REST API v1', 'Public REST API under /api/v1.', true, false, []],
        ['messaging.kafka', 'Kafka event streaming', 'Outbox relay publishes domain events to Kafka. When disabled events stay in the outbox (not lost).', true, false, ['service:kafka']],
        ['notifications.email', 'E-mail notifications', 'SMTP delivery.', true, false, ['service:mailer']],
        ['notifications.telegram', 'Telegram notifications', 'Telegram Bot API delivery.', false, false, ['channel:telegram']],
        ['notifications.sms', 'SMS notifications', 'HTTP SMS gateway delivery.', false, false, ['channel:sms']],
        ['notifications.webpush', 'Web Push notifications', 'Browser push (VAPID).', false, false, ['channel:webpush']],
        ['ui.partial_navigation', 'Partial page navigation', 'HTMX-based partial updates in admin and web UI.', true, false, []],
        ['module.projects', 'Projects module', 'Sample business module.', true, false, []],
        ['devtools.gii', 'Enterprise Gii', 'Code generators — automatically unavailable in production.', true, false, ['env:non-prod']],
    ];

    public const SCHEDULES = [
        ['platform.partitions', 'Maintain audit partitions', '15 3 * * *', 'platform.maintenance.partitions', [], true],
        ['platform.cleanup', 'Retention cleanup', '20 * * * *', 'platform.maintenance.cleanup', [], true],
        ['platform.flags', 'Apply scheduled feature-flag rollouts', '* * * * *', 'platform.flags.apply_schedule', [], true],
        ['platform.health', 'Probe external dependencies', '*/5 * * * *', 'platform.health.probe', [], true],
        ['platform.reaper', 'Recover stuck jobs and lost workers', '* * * * *', 'platform.queue.reap', [], true],
        ['platform.backup', 'Nightly logical backup', '0 2 * * *', 'platform.backup.run', ['type' => 'logical'], false],
        ['platform.backup.verify', 'Weekly restore test', '0 4 * * 0', 'platform.backup.verify_latest', [], false],
    ];

    public const TOPICS = [
        ['platform.audit', 6, 'Security-relevant audit events'],
        ['platform.users', 3, 'User lifecycle events'],
        ['platform.notifications', 3, 'Notification delivery events'],
        ['platform.dlq', 3, 'Dead letters (failed after all retries)'],
        ['platform.retry.1m', 3, 'Delayed retry topic (1 minute)'],
        ['platform.retry.10m', 3, 'Delayed retry topic (10 minutes)'],
        ['projects.events', 6, 'Projects module domain events'],
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string,int> */
    public function seed(): array
    {
        $stats = ['tenants' => 0, 'flags' => 0, 'schedules' => 0, 'topics' => 0, 'templates' => 0];

        if (!(new Query($this->db))->from('tenants')->where(['code' => self::DEFAULT_TENANT])->exists()) {
            $this->db->createCommand()->insert('tenants', ['code' => self::DEFAULT_TENANT, 'name' => 'Default organization'])->execute();
            $stats['tenants']++;
        }

        foreach (self::FLAGS as [$code, $name, $desc, $enabled, $critical, $deps]) {
            $stats['flags'] += $this->db->createCommand(
                'INSERT INTO feature_flags (code, name, description, enabled, status, is_critical, dependencies)
                 VALUES (:c, :n, :d, :e, :s, :cr, :dep) ON CONFLICT (code) DO NOTHING',
                [':c' => $code, ':n' => $name, ':d' => $desc, ':e' => $enabled, ':s' => $enabled ? 'enabled' : 'disabled', ':cr' => $critical, ':dep' => json_encode($deps)],
            )->execute();
        }

        $next = (new \DateTimeImmutable('+1 minute'))->format(DATE_ATOM);
        foreach (self::SCHEDULES as [$code, $name, $cron, $job, $payload, $enabled]) {
            $stats['schedules'] += $this->db->createCommand(
                'INSERT INTO schedules (code, name, cron_expression, job_type, payload, enabled, is_system, next_run_at, queue)
                 VALUES (:c, :n, :cron, :j, :p, :e, true, :next, :q) ON CONFLICT (code) DO NOTHING',
                [':c' => $code, ':n' => $name, ':cron' => $cron, ':j' => $job, ':p' => json_encode($payload, JSON_FORCE_OBJECT), ':e' => $enabled, ':next' => $next, ':q' => 'default'],
            )->execute();
        }

        foreach (self::TOPICS as [$name, $partitions, $desc]) {
            $stats['topics'] += $this->db->createCommand(
                'INSERT INTO kafka_topics (name, partitions, description) VALUES (:n, :p, :d) ON CONFLICT (name) DO NOTHING',
                [':n' => $name, ':p' => $partitions, ':d' => $desc],
            )->execute();
        }

        foreach ($this->templates() as [$code, $channel, $locale, $subject, $body]) {
            $stats['templates'] += $this->db->createCommand(
                'INSERT INTO notification_templates (code, channel, locale, subject, body) VALUES (:c, :ch, :l, :s, :b) ON CONFLICT (code, channel, locale) DO NOTHING',
                [':c' => $code, ':ch' => $channel, ':l' => $locale, ':s' => $subject, ':b' => $body],
            )->execute();
        }

        return $stats;
    }

    private function templates(): array
    {
        $t = [];
        $defs = [
            'security.new_login' => [
                'en' => ['New sign-in to your account', "Hello {name},\n\nA new sign-in to your account was detected from {ip} at {time}.\nIf this was not you, change your password immediately and contact the security team."],
                'ru' => ['Новый вход в учётную запись', "Здравствуйте, {name}!\n\nОбнаружен новый вход в вашу учётную запись с адреса {ip} в {time}.\nЕсли это были не вы, немедленно смените пароль и сообщите в службу безопасности."],
                'uz' => ["Hisobingizga yangi kirish", "Assalomu alaykum, {name}!\n\nHisobingizga {ip} manzilidan {time} da yangi kirish aniqlandi.\nAgar bu siz bo'lmasangiz, darhol parolni almashtiring va xavfsizlik xizmatiga xabar bering."],
            ],
            'project.status_changed' => [
                'en' => ['Project {project} is now {status}', "Project \"{project}\" changed status to {status} by {actor}."],
                'ru' => ['Проект {project}: статус {status}', "Статус проекта «{project}» изменён на {status} пользователем {actor}."],
                'uz' => ["{project} loyihasi holati: {status}", "\"{project}\" loyihasi holati {actor} tomonidan {status} ga o'zgartirildi."],
            ],
            'approval.requested' => [
                'en' => ['Approval required: {title}', "{requester} requested your approval: {title}."],
                'ru' => ['Требуется согласование: {title}', "{requester} запросил(а) согласование: {title}."],
                'uz' => ['Tasdiqlash talab etiladi: {title}', "{requester} tasdiqlashingizni so'radi: {title}."],
            ],
            'incident.opened' => [
                'en' => ['[{severity}] Incident #{number}: {title}', "Incident #{number} ({severity}) opened on {service}: {title}"],
                'ru' => ['[{severity}] Инцидент #{number}: {title}', "Открыт инцидент #{number} ({severity}) в {service}: {title}"],
                'uz' => ['[{severity}] Insident #{number}: {title}', "{service} xizmatida #{number} ({severity}) insident ochildi: {title}"],
            ],
        ];
        foreach ($defs as $code => $locales) {
            foreach ($locales as $locale => [$subject, $body]) {
                foreach (['email', 'inapp', 'telegram'] as $channel) {
                    $t[] = [$code, $channel, $locale, $subject, $body];
                }
            }
        }
        return $t;
    }
}
