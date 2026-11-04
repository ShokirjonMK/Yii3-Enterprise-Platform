<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Integration work package (07-09, 21-23):
 *  - integrations.auth_config  non-secret auth parameters (api_key header name, basic username, hmac header names)
 *  - webhook_endpoints         description, id header, last received / rotated timestamps, updated_at trigger
 *  - push_subscriptions        Web Push (VAPID) subscriptions per user
 */
final class M261104040001IntegrationsExtras implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            ALTER TABLE integrations ADD COLUMN IF NOT EXISTS auth_config jsonb NOT NULL DEFAULT '{}'::jsonb;

            ALTER TABLE webhook_endpoints
                ADD COLUMN IF NOT EXISTS description       text         NOT NULL DEFAULT '',
                ADD COLUMN IF NOT EXISTS id_header         varchar(64)  NOT NULL DEFAULT 'X-Webhook-Id',
                ADD COLUMN IF NOT EXISTS secret_rotated_at timestamptz,
                ADD COLUMN IF NOT EXISTS last_received_at  timestamptz,
                ADD COLUMN IF NOT EXISTS version           integer      NOT NULL DEFAULT 1;
            ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_tolerance CHECK (tolerance_seconds BETWEEN 30 AND 3600);
            CREATE TRIGGER webhook_endpoints_touch BEFORE UPDATE ON webhook_endpoints FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();
            CREATE INDEX IF NOT EXISTS webhook_events_endpoint_idx ON webhook_events (endpoint_id, received_at DESC);

            CREATE TABLE push_subscriptions (
                id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id           uuid          NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                endpoint          text          NOT NULL,
                endpoint_hash     char(64)      NOT NULL UNIQUE,
                p256dh            varchar(255)  NOT NULL,
                auth              varchar(255)  NOT NULL,
                content_encoding  varchar(16)   NOT NULL DEFAULT 'aes128gcm' CHECK (content_encoding IN ('aes128gcm','aesgcm')),
                user_agent        varchar(500),
                created_at        timestamptz   NOT NULL DEFAULT now(),
                last_used_at      timestamptz,
                last_error        text
            );
            CREATE INDEX push_subscriptions_user_idx ON push_subscriptions (user_id);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS push_subscriptions;
            DROP TRIGGER IF EXISTS webhook_endpoints_touch ON webhook_endpoints;
            DROP INDEX IF EXISTS webhook_events_endpoint_idx;
            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS webhook_endpoints_tolerance,
                DROP COLUMN IF EXISTS description, DROP COLUMN IF EXISTS id_header, DROP COLUMN IF EXISTS secret_rotated_at,
                DROP COLUMN IF EXISTS last_received_at, DROP COLUMN IF EXISTS version;
            ALTER TABLE integrations DROP COLUMN IF EXISTS auth_config;
            SQL);
    }
}
