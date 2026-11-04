<?php

declare(strict_types=1);

/**
 * Template: integration registration migration.
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
 * Registers integration "{{code}}" used by {{Module}}Connector: disabled and unconfigured (no base URL,
 * no secret) until an operator configures it in Admin → Integrations. Timeout 5 s, connect timeout 2 s,
 * 3 retries with 300 ms exponential backoff, circuit breaker after 5 failures for 60 s.
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand(
            "INSERT INTO integrations (code, name, description, enabled, auth_type, timeout_ms, connect_timeout_ms, max_retries, backoff_ms,
                                       circuit_threshold, circuit_cooldown_s, status)
             VALUES ('{{code}}', '{{label}} connector', 'Outbound connector of the {{label}} module (Enterprise Gii).', false, 'bearer',
                     5000, 2000, 3, 300, 5, 60, 'unconfigured')
             ON CONFLICT (code) DO NOTHING",
        )->execute();
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM integrations WHERE code = '{{code}}' AND base_url IS NULL")->execute();
    }
}

PHP, ['__CLASS__' => $m->migrationClass('Integration')]);
