<?php

declare(strict_types=1);

/**
 * Template: consumer group config (config/common/events/<module>.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$types = ['EVENT_CREATED', 'EVENT_UPDATED'];
if ($m->hasStatus()) {
    $types[] = 'EVENT_STATUS_CHANGED';
}
$types[] = 'EVENT_DELETED';
$lines = array_map(static fn($t) => "            {{Entity}}::$t => {{Entity}}ActivityProjector::class,", $types);

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use App\Modules\{{Module}}\Domain\{{Entity}};
use App\Modules\{{Module}}\Infrastructure\{{Entity}}ActivityProjector;

/**
 * Consumer group maintaining the {{entity_snake}}_activity read model.
 * Run: ./yii kafka:consume --group=__GROUP__
 */
return [
    '__GROUP__' => [
        'topics' => ['{{topic}}'],
        'handlers' => [
__LINES__
        ],
    ],
];

PHP, ['__GROUP__' => $m->consumerGroup(), '__LINES__' => implode("\n", $lines)]);
