<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Templates used by Admin → Notifications: "platform.broadcast" ({subject}/{message}) and "platform.test".
 * Existing rows are never overwritten.
 */
final class M261104040003NotificationTemplates implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $defs = [
            'platform.broadcast' => ['en' => ['{subject}', '{message}'], 'ru' => ['{subject}', '{message}'], 'uz' => ['{subject}', '{message}']],
            'platform.test' => [
                'en' => ['Test notification', "Hello {name},\n\n{message}"],
                'ru' => ['Тестовое уведомление', "Здравствуйте, {name}!\n\n{message}"],
                'uz' => ['Sinov xabarnomasi', "Assalomu alaykum, {name}!\n\n{message}"],
            ],
        ];
        foreach ($defs as $code => $locales) {
            foreach ($locales as $locale => [$subject, $body]) {
                foreach (['inapp', 'email', 'telegram', 'sms', 'webpush'] as $channel) {
                    $b->getDb()->createCommand(
                        'INSERT INTO notification_templates (code, channel, locale, subject, body) VALUES (:c, :ch, :l, :s, :b) ON CONFLICT (code, channel, locale) DO NOTHING',
                        [':c' => $code, ':ch' => $channel, ':l' => $locale, ':s' => $subject, ':b' => $body],
                    )->execute();
                }
            }
        }
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM notification_templates WHERE code IN ('platform.broadcast', 'platform.test') AND updated_by IS NULL")->execute();
    }
}
