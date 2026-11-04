<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261104000005IntegrationsNotifications implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE integrations (
                id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code                   varchar(64)  NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
                name                   varchar(255) NOT NULL,
                description            text         NOT NULL DEFAULT '',
                base_url               varchar(1024),
                enabled                boolean      NOT NULL DEFAULT false,
                auth_type              varchar(16)  NOT NULL DEFAULT 'none' CHECK (auth_type IN ('none','bearer','basic','api_key','hmac')),
                secret_enc             text,
                headers                jsonb        NOT NULL DEFAULT '{}'::jsonb,
                timeout_ms             integer      NOT NULL DEFAULT 10000 CHECK (timeout_ms BETWEEN 100 AND 120000),
                connect_timeout_ms     integer      NOT NULL DEFAULT 3000 CHECK (connect_timeout_ms BETWEEN 100 AND 60000),
                max_retries            smallint     NOT NULL DEFAULT 3 CHECK (max_retries BETWEEN 0 AND 10),
                backoff_ms             integer      NOT NULL DEFAULT 200 CHECK (backoff_ms BETWEEN 0 AND 60000),
                circuit_threshold      smallint     NOT NULL DEFAULT 5 CHECK (circuit_threshold BETWEEN 1 AND 1000),
                circuit_cooldown_s     integer      NOT NULL DEFAULT 30 CHECK (circuit_cooldown_s BETWEEN 1 AND 3600),
                rate_limit_per_minute  integer      NOT NULL DEFAULT 0 CHECK (rate_limit_per_minute >= 0),
                health_path            varchar(512),
                allow_private_network  boolean      NOT NULL DEFAULT false,
                status                 varchar(16)  NOT NULL DEFAULT 'unconfigured'
                    CHECK (status IN ('unconfigured','healthy','degraded','failed','maintenance')),
                last_health_at         timestamptz,
                last_error             text,
                version                integer      NOT NULL DEFAULT 1,
                created_at             timestamptz  NOT NULL DEFAULT now(),
                updated_at             timestamptz  NOT NULL DEFAULT now()
            );
            CREATE TRIGGER integrations_touch BEFORE UPDATE ON integrations FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE integration_logs (
                id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                integration_id  uuid         REFERENCES integrations (id) ON DELETE CASCADE,
                correlation_id  varchar(128),
                method          varchar(8)   NOT NULL,
                url             varchar(2048) NOT NULL,
                request_headers jsonb        NOT NULL DEFAULT '{}'::jsonb,
                request_sample  text,
                status_code     smallint,
                response_sample text,
                duration_ms     integer,
                attempt         smallint     NOT NULL DEFAULT 1,
                error_class     varchar(32),
                error_message   text,
                created_at      timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX integration_logs_integration_idx ON integration_logs (integration_id, created_at DESC);
            CREATE INDEX integration_logs_created_idx ON integration_logs (created_at);

            CREATE TABLE webhook_endpoints (
                id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code              varchar(64)  NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
                name              varchar(255) NOT NULL,
                enabled           boolean      NOT NULL DEFAULT false,
                secret_enc        text,
                signature_header  varchar(64)  NOT NULL DEFAULT 'X-Signature',
                timestamp_header  varchar(64)  NOT NULL DEFAULT 'X-Timestamp',
                tolerance_seconds integer      NOT NULL DEFAULT 300,
                job_type          varchar(255),
                created_at        timestamptz  NOT NULL DEFAULT now(),
                updated_at        timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE webhook_events (
                id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                endpoint_id      uuid         NOT NULL REFERENCES webhook_endpoints (id) ON DELETE CASCADE,
                external_id      varchar(255) NOT NULL,
                signature_valid  boolean      NOT NULL,
                payload          jsonb,
                received_at      timestamptz  NOT NULL DEFAULT now(),
                processed_at     timestamptz,
                job_id           uuid,
                CONSTRAINT webhook_events_idempotent UNIQUE (endpoint_id, external_id)
            );

            CREATE TABLE notification_channels (
                code        varchar(16)  PRIMARY KEY CHECK (code IN ('email','telegram','sms','webpush','inapp')),
                name        varchar(128) NOT NULL,
                enabled     boolean      NOT NULL DEFAULT false,
                config      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                secret_enc  text,
                status      varchar(16)  NOT NULL DEFAULT 'unconfigured'
                    CHECK (status IN ('unconfigured','healthy','degraded','failed','maintenance')),
                last_error  text,
                updated_at  timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE notification_templates (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code        varchar(96)  NOT NULL,
                channel     varchar(16)  NOT NULL REFERENCES notification_channels (code),
                locale      varchar(8)   NOT NULL,
                subject     varchar(255) NOT NULL DEFAULT '',
                body        text         NOT NULL,
                version     integer      NOT NULL DEFAULT 1,
                updated_by  uuid,
                updated_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT notification_templates_uq UNIQUE (code, channel, locale)
            );

            CREATE TABLE notifications (
                id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id              uuid         REFERENCES users (id) ON DELETE CASCADE,
                tenant_id            uuid,
                category             varchar(64)  NOT NULL DEFAULT 'system',
                template_code        varchar(96),
                channel              varchar(16)  NOT NULL REFERENCES notification_channels (code),
                recipient            varchar(255) NOT NULL,
                subject              varchar(255) NOT NULL DEFAULT '',
                body                 text         NOT NULL,
                data                 jsonb        NOT NULL DEFAULT '{}'::jsonb,
                status               varchar(16)  NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued','sending','sent','delivered','failed','read','skipped')),
                attempts             smallint     NOT NULL DEFAULT 0,
                last_error           text,
                provider_message_id  varchar(255),
                job_id               uuid,
                created_at           timestamptz  NOT NULL DEFAULT now(),
                sent_at              timestamptz,
                read_at              timestamptz
            );
            CREATE INDEX notifications_user_idx ON notifications (user_id, channel, created_at DESC);
            CREATE INDEX notifications_status_idx ON notifications (status, created_at DESC);
            CREATE INDEX notifications_inapp_unread_idx ON notifications (user_id) WHERE channel = 'inapp' AND read_at IS NULL;

            CREATE TABLE notification_preferences (
                user_id   uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                category  varchar(64) NOT NULL,
                channel   varchar(16) NOT NULL REFERENCES notification_channels (code),
                enabled   boolean     NOT NULL DEFAULT true,
                PRIMARY KEY (user_id, category, channel)
            );

            INSERT INTO notification_channels (code, name, enabled, status) VALUES
                ('inapp', 'In-app', true, 'healthy'),
                ('email', 'Email (SMTP)', false, 'unconfigured'),
                ('telegram', 'Telegram Bot', false, 'unconfigured'),
                ('sms', 'SMS gateway', false, 'unconfigured'),
                ('webpush', 'Web Push (VAPID)', false, 'unconfigured');
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS notification_preferences, notifications, notification_templates, notification_channels, webhook_events, webhook_endpoints, integration_logs, integrations CASCADE');
    }
}
