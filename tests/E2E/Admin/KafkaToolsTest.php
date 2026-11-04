<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Admin → 11 Kafka: Handlers, Publish (broker-confirmed, step-up, audited) and Settings tabs.
 *
 * Author: ShokirjonMK
 */
final class KafkaToolsTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        Fixtures::enableFeatures(['messaging.kafka']);
    }

    protected function tearDown(): void
    {
        Fixtures::restoreFeatures();
        Fixtures::cleanup();
    }

    public function testHandlersTabListsAttributeHandlers(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        self::assertSame(200, $web->get('/admin/kafka/handlers')->getStatusCode());
        $body = $web->body();
        self::assertStringContainsString('InboundNotificationHandler', $body);
        self::assertStringContainsString('notifications-inbound', $body);
        self::assertStringContainsString('#[KafkaChannel]', $body);
        self::assertStringContainsString('ProjectActivityProjector', $body, 'config-file handlers listed too');
    }

    public function testPublishTabSendsConfirmedMessage(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        AdminSession::reauth($web, $user);
        $web->get('/admin/kafka/publish');
        self::assertStringContainsString('platform.selftest', $web->body(), 'registry topics offered');
        $web->post('/admin/kafka/publish', ['topic' => 'platform.selftest', 'key' => 'e2e', 'payload' => '{"e2e":true}']);
        self::assertStringContainsString('1 message(s) confirmed by Kafka', $web->body());

        $web->post('/admin/kafka/publish', ['topic' => 'platform.selftest', 'payload' => '[{"a":1},{"a":2}]', 'batch' => '1']);
        self::assertStringContainsString('2 message(s) confirmed by Kafka', $web->body());

        $r = $web->post('/admin/kafka/publish', ['topic' => 'platform.selftest', 'payload' => '{not json']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Invalid JSON', $web->body());

        self::assertTrue((bool) Fixtures::db()->createCommand(
            "SELECT 1 FROM audit_log WHERE action = 'kafka.message.published' AND actor_id = :a",
            [':a' => $user['id']],
        )->queryScalar());
    }

    public function testSettingsTabSavesAndValidates(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        $web->get('/admin/kafka/settings');
        $body = $web->body();
        foreach (['kafka_brokers', 'kafka_sasl_password', 'kafka_producer_acks', 'kafka_retry_attempts', 'kafka_on_failure'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $body, $field);
        }
        self::assertStringContainsString('set by environment', $body, 'KAFKA_BROKERS from env is read-only');

        AdminSession::reauth($web, $user);
        $web->get('/admin/kafka/settings');
        $r = $web->post('/admin/kafka/settings', ['kafka_retry_attempts' => '99']);
        self::assertSame(422, $r->getStatusCode());

        $web->get('/admin/kafka/settings');
        $web->post('/admin/kafka/settings', ['kafka_retry_attempts' => '2', 'kafka_retry_backoff_ms' => '250']);
        self::assertStringContainsString('Saved 2 setting(s)', $web->body());
        $db = Fixtures::db();
        self::assertSame('2', (string) $db->createCommand("SELECT value FROM settings WHERE key = 'kafka.retry_attempts' AND scope = 'global'")->queryScalar());

        // restore defaults
        $web->get('/admin/kafka/settings');
        $web->post('/admin/kafka/settings', ['kafka_retry_attempts' => '1', 'kafka_retry_backoff_ms' => '500']);
    }
}
