<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
$tabs = ['definitions' => ['admin.workflow', 'Workflows'], 'instances' => ['admin.workflow.instances', 'Instances'], 'approvals' => ['admin.workflow.approvals', 'Approvals']];
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Workflow & Approvals') ?></h1>
    <p class="page-head__sub"><?= $h->t('JSON state machines with permission-checked transitions, optimistic locking, history and four-eyes approvals.') ?></p></div></div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($tabs as $key => [$route, $label]): ?>
        <a href="<?= $h->url($route) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
