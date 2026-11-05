<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $wf
 * @var App\Platform\Workflow\WorkflowDefinition $def
 * @var array $report
 * @var array<string,int> $counts
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($wf['name']);
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow'), $wf['code']]);
$manage = $can('workflow.manage');
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($wf['name']) ?> <span class="tag"><?= $h->e($wf['code']) ?></span></h1>
        <p class="page-head__sub row"><?= Sql::bool($wf['enabled']) ? $h->badge('enabled') : $h->badge('disabled') ?> <span class="small muted"><?= $h->t('entity {e} · version {v}', ['e' => $wf['entity_type'], 'v' => $wf['version']]) ?></span></p></div>
    <?php if ($manage): ?>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.workflow.edit', ['id' => $wf['id']]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit definition') ?></a>
        <form method="post" action="<?= $h->url('admin.workflow.delete', ['id' => $wf['id']]) ?>" data-confirm="<?= $h->t('Delete this workflow? Only possible without instances.') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
    </div>
    <?php endif ?>
</div>
<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('States') ?></h2></div>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('State') ?></th><th><?= $h->t('Label') ?></th><th class="num"><?= $h->t('Instances') ?></th></tr></thead>
                <tbody><?php foreach ($def->states as $s): ?>
                    <tr><td><span class="tag"><?= $h->e($s) ?></span><?= $s === $def->initial ? ' ' . $h->badge('info', $h->tr('initial')) : '' ?></td><td><?= $h->e($def->label($s)) ?></td>
                        <td class="num"><a href="<?= $h->url('admin.workflow.instances', [], ['workflow' => $wf['code'], 'state' => $s]) ?>"><?= (int) ($counts[$s] ?? 0) ?></a></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
        </div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Transitions') ?></h2></div>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Transition') ?></th><th><?= $h->t('From') ?></th><th><?= $h->t('To') ?></th><th><?= $h->t('Permission') ?></th><th><?= $h->t('Approval') ?></th></tr></thead>
                <tbody><?php foreach ($def->transitions as $name => $t): ?>
                    <tr><td><strong><?= $h->e($t['label']) ?></strong> <span class="tag"><?= $h->e($name) ?></span></td>
                        <td><?php foreach ($t['from'] as $f): ?><span class="tag"><?= $h->e($f) ?></span><?php endforeach ?></td>
                        <td><span class="tag"><?= $h->e($t['to']) ?></span></td>
                        <td class="mono small"><?= $h->e($t['permission'] ?? '—') ?></td>
                        <td class="small"><?= $t['approval'] ? $h->t('{n} × {p}', ['n' => $t['approval']['required_approvals'], 'p' => $t['approval']['permission']]) . ($t['approval']['expires_hours'] ? ' · ' . $h->t('{h} h', ['h' => $t['approval']['expires_hours']]) : '') : '—' ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('Validation report') ?></h2></div><div class="card__body"><?= $this->render('./_report', ['report' => $report]) ?></div></div>
        <?php if ($manage && Sql::bool($wf['enabled'])): ?>
        <form class="card" method="post" action="<?= $h->url('admin.workflow.start', ['id' => $wf['id']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="card__head"><h2><?= $h->t('Start an instance') ?></h2></div>
            <div class="card__body"><div class="field"><label for="start-entity"><?= $h->t('{type} id', ['type' => $wf['entity_type']]) ?></label><input type="text" id="start-entity" name="entity_id" required maxlength="96"></div>
                <div class="field__hint"><?= $h->t('Business modules start instances from code (WorkflowEngine::start); this form is for operators and testing.') ?></div></div>
            <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Start') ?></button></div>
        </form>
        <?php endif ?>
    </div>
</div>
