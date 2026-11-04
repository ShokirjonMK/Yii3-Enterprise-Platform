<?php

declare(strict_types=1);

/**
 * Template: read model + topic registry migration.
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * {{label}} events: read model {{entity_snake}}_activity (maintained by the Kafka consumer group __GROUP__)
 * and the topic {{topic}} in the Kafka topic registry (`./yii kafka:provision` creates it).
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE {{entity_snake}}_activity (
                id           bigserial PRIMARY KEY,
                event_id     varchar(128) NOT NULL UNIQUE,
                entity_id    uuid         NOT NULL,
                tenant_id    uuid,
                event_type   varchar(64)  NOT NULL,
                actor_id     uuid,
                summary      varchar(500) NOT NULL,
                payload      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at  timestamptz  NOT NULL,
                recorded_at  timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX {{entity_snake}}_activity_entity_idx ON {{entity_snake}}_activity (entity_id, occurred_at DESC);
            INSERT INTO kafka_topics (name, partitions, description) VALUES ('{{topic}}', 3, '{{label}} module domain events')
            ON CONFLICT (name) DO NOTHING;
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS {{entity_snake}}_activity;
            DELETE FROM kafka_topics WHERE name = '{{topic}}' AND provisioned_at IS NULL;
            SQL);
    }
}

PHP, ['__CLASS__' => $m->migrationClass('Events'), '__GROUP__' => $m->consumerGroup()]);
