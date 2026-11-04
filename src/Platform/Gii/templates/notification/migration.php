<?php

declare(strict_types=1);

/**
 * Template: notification template seed (notification_templates, uz/ru/en).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$status = $m->hasStatus();
$defs = $status ? [
    'en' => ['{record}: status {status}', '"{record}" changed status to {status} ({actor}).'],
    'ru' => ['{record}: статус {status}', 'Статус «{record}» изменён на {status} пользователем {actor}.'],
    'uz' => ['{record}: holat {status}', '"{record}" holati {actor} tomonidan {status} ga o\'zgartirildi.'],
] : [
    'en' => ['New record: {record}', '{actor} created "{record}".'],
    'ru' => ['Новая запись: {record}', '{actor} создал(а) «{record}».'],
    'uz' => ['Yangi yozuv: {record}', '{actor} "{record}" yozuvini yaratdi.'],
];
$lines = [];
foreach ($defs as $locale => [$subject, $body]) {
    $lines[] = "            '$locale' => [" . var_export($subject, true) . ', ' . var_export($body, true) . '],';
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Notification template "__TEMPLATE__" ({record}, {status}, {actor}) in en/ru/uz for the in-app, e-mail
 * and Telegram channels. Existing rows (possibly edited by operators) are never overwritten.
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $locales = [
__LINES__
        ];
        $channels = (new \Yiisoft\Db\Query\Query($b->getDb()))->select('code')->from('notification_channels')
            ->where(['code' => ['inapp', 'email', 'telegram']])->column();
        foreach ($locales as $locale => [$subject, $body]) {
            foreach ($channels as $channel) {
                $b->getDb()->createCommand(
                    'INSERT INTO notification_templates (code, channel, locale, subject, body) VALUES (:c, :ch, :l, :s, :b) ON CONFLICT (code, channel, locale) DO NOTHING',
                    [':c' => '__TEMPLATE__', ':ch' => $channel, ':l' => $locale, ':s' => $subject, ':b' => $body],
                )->execute();
            }
        }
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM notification_templates WHERE code = '__TEMPLATE__' AND updated_by IS NULL")->execute();
    }
}

PHP, ['__CLASS__' => $m->migrationClass('Notifications'), '__TEMPLATE__' => $m->notifyTemplate(), '__LINES__' => implode("\n", $lines)]);
