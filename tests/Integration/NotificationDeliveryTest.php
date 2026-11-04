<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Notifications\Notifier;
use App\Platform\Queue\Worker;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/**
 * In-app + e-mail (real SMTP → Mailpit) delivery through the queue worker.
 */
final class NotificationDeliveryTest extends IntegrationTestCase
{
    public function testInAppAndEmailDelivery(): void
    {
        $this->db->createCommand()->update('notification_channels', ['enabled' => true, 'status' => 'healthy'], ['code' => 'email'])->execute();
        $u = Fixtures::user(['user']);
        /** @var Notifier $notifier */
        $notifier = $this->get(Notifier::class);
        $ids = $notifier->notify($u['id'], 'project.status_changed', ['project' => 'Alpha', 'status' => 'active', 'actor' => 'tester'], ['inapp', 'email', 'sms']);
        self::assertCount(3, $ids);

        $rows = (new Query($this->db))->from('notifications')->where(['id' => $ids])->indexBy('channel')->all();
        self::assertSame('delivered', $rows['inapp']['status']);
        self::assertStringContainsString('Alpha', $rows['inapp']['body']);
        self::assertSame('queued', $rows['email']['status']);
        self::assertSame('skipped', $rows['sms']['status'], 'no phone number → skipped, not lost silently');

        $this->db->createCommand("UPDATE jobs SET status = 'cancelled' WHERE queue = 'notifications' AND status = 'pending' AND id <> :id", [':id' => $rows['email']['job_id']])->execute();
        $this->get(Worker::class)->run(['notifications'], 0.1, 5, 512, true);
        $email = (new Query($this->db))->from('notifications')->where(['id' => $rows['email']['id']])->one();
        self::assertSame('sent', $email['status'], (string) $email['last_error']);
        self::assertNotEmpty($email['provider_message_id']);
    }
}
