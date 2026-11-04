<?php

declare(strict_types=1);

/**
 * Template: WorkflowEngine bridge (src/Modules/<Module>/Application/<Entity>Workflow.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Application;

use App\Modules\{{Module}}\Domain\{{Entity}};
use App\Modules\{{Module}}\Domain\{{Entity}}Status;
use App\Platform\Workflow\WorkflowEngine;
use App\Shared\Infrastructure\Database\Sql;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Bridge between the {{entityLabel}} aggregate and the platform workflow definition "__CODE__".
 * The domain state machine ({{Entity}}Status) stays authoritative; the workflow instance mirrors the state
 * (history, Admin → Workflow, optional approvals/permissions configured by operators).
 * When the definition is missing or disabled every method is a no-op. Engine failures never break the
 * business transaction (the engine runs in a nested transaction / savepoint).
 */
final class {{Entity}}Workflow
{
    public const CODE = '__CODE__';
    public const ENTITY = '{{entity_snake}}';

    private ?bool $enabled = null;

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        if ($this->enabled === null) {
            $row = (new Query($this->db))->select(['enabled'])->from('workflows')->where(['code' => self::CODE, 'entity_type' => self::ENTITY])->one();
            $this->enabled = $row !== null && Sql::bool($row['enabled']);
        }
        return $this->enabled;
    }

    public function start({{Entity}} $entity, string $actorId): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $this->engine->start(self::CODE, self::ENTITY, $entity->id, $actorId, $entity->tenantId, ['title' => $entity->displayTitle()]);
        } catch (Throwable $e) {
            $this->logger->warning('{{entityLabel}} workflow start failed: {e}', ['e' => $e->getMessage()]);
        }
    }

    /** Mirrors a domain transition onto the workflow instance (transition "to_<state>"). */
    public function mirror({{Entity}} $entity, {{Entity}}Status $from, {{Entity}}Status $to, string $actorId, string $comment = ''): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $instance = $this->engine->instanceFor(self::CODE, self::ENTITY, $entity->id);
            if ($instance === null || $instance['state'] !== $from->value) {
                return;
            }
            $this->engine->apply((string) $instance['id'], 'to_' . $to->value, $actorId, (int) $instance['version'], $comment);
        } catch (Throwable $e) {
            $this->logger->warning('{{entityLabel}} workflow mirror failed: {e}', ['e' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed>|null */
    public function instance({{Entity}} $entity): ?array
    {
        return $this->enabled() ? $this->engine->instanceFor(self::CODE, self::ENTITY, $entity->id) : null;
    }
}

PHP, ['__CODE__' => $m->workflowCode()]);
