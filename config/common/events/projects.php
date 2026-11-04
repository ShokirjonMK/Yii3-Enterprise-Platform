<?php

declare(strict_types=1);

use App\Modules\Projects\Infrastructure\ProjectActivityProjector;

/**
 * Consumer group maintaining the project_activity read model.
 * Run: ./yii kafka:consume --group=projects-audit
 */
return [
    'projects-audit' => [
        'topics' => ['projects.events'],
        'handlers' => [
            'project.created' => ProjectActivityProjector::class,
            'project.updated' => ProjectActivityProjector::class,
            'project.status_changed' => ProjectActivityProjector::class,
            'project.archived' => ProjectActivityProjector::class,
        ],
    ],
];
