<?php

declare(strict_types=1);

/**
 * Template: WorkflowEngine definition seed (like migrations/M261104040002WorkflowSeed.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$s = $m->statusField();
$transitions = [];
foreach ($s->options as $i => $to) {
    if ($i === 0) {
        continue;
    }
    $from = array_slice($s->options, 0, $i);
    $transitions[] = "                'to_$to' => ['label' => " . var_export(ucfirst(str_replace('_', ' ', $to)), true) . ", 'from' => ['" . implode("', '", $from) . "'], 'to' => '$to'],";
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Seeds the workflow definition "__CODE__" (entity_type "{{entity_snake}}") mirrored by {{Entity}}Workflow:
 *   __DIAGRAM__
 * Transitions carry no permission of their own ({{Entity}}Service authorises access); operators can add
 * permissions or approvals in Admin → Workflow without code changes.
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $definition = json_encode([
            'states' => __STATES__,
            'initial' => '__INITIAL__',
            'transitions' => [
__TRANSITIONS__
            ],
        ], JSON_UNESCAPED_SLASHES);
        $b->getDb()->createCommand(
            "INSERT INTO workflows (code, name, entity_type, definition, enabled) VALUES ('__CODE__', '{{entityLabel}} lifecycle', '{{entity_snake}}', CAST(:d AS jsonb), true)
             ON CONFLICT (code) DO NOTHING",
            [':d' => $definition],
        )->execute();
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM workflows WHERE code = '__CODE__' AND NOT EXISTS (SELECT 1 FROM workflow_instances i WHERE i.workflow_id = workflows.id)")->execute();
    }
}

PHP, [
    '__CLASS__' => $m->migrationClass('Workflow'),
    '__CODE__' => $m->workflowCode(),
    '__DIAGRAM__' => implode(' → ', $s->options) . ' (forward-only)',
    '__STATES__' => "['" . implode("', '", $s->options) . "']",
    '__INITIAL__' => $s->options[0],
    '__TRANSITIONS__' => implode("\n", $transitions),
]);
