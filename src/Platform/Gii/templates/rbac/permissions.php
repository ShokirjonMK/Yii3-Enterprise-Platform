<?php

declare(strict_types=1);

/**
 * Template: RBAC catalog (src/Modules/<Module>/config/permissions.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * {{label}} module RBAC catalog (merged with the platform catalog by `./yii rbac:sync`).
 * Existing roles are extended (permissions are merged, descriptions kept).
 */
return [
    'permissions' => [
        '{{code}}.view' => 'View {{labelLower}} of own tenant',
        '{{code}}.create' => 'Create {{labelLower}}',
        '{{code}}.update' => 'Edit {{labelLower}} and change their status',
        '{{code}}.delete' => 'Delete {{labelLower}}',
    ],
    'roles' => [
        'business_admin' => [
            'permissions' => ['{{code}}.view', '{{code}}.create', '{{code}}.update', '{{code}}.delete'],
        ],
        'user' => ['permissions' => ['{{code}}.view']],
        'developer' => ['permissions' => ['{{code}}.view']],
    ],
];

PHP);
