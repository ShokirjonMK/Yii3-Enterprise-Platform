<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array|null $wf
 * @var array $values
 * @var array<string,list<string>> $errors
 * @var array{errors:list<string>, warnings:list<string>} $report
 * @var string $csrf
 */
$isNew = $wf === null;
$this->setTitle($isNew ? $h->tr('New workflow') : $h->tr('Edit workflow {code}', ['code' => $wf['code']]));
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow')] + ($isNew ? [] : [$wf['code'] => $h->url('admin.workflow.show', ['id' => $wf['id']])]) + [$isNew ? $h->tr('New') : $h->tr('Edit')]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$cls = static fn(string $k): string => isset($errors[$k]) ? 'field field--invalid' : 'field';
$enabled = $isNew ? (bool) $v('enabled', true) : Sql::bool($v('enabled', false));
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $isNew ? $h->t('New workflow') : $h->t('Edit workflow') ?></h1></div></div>
<form class="grid grid--sidebar" id="workflow-form" method="post" action="<?= $isNew ? $h->url('admin.workflow.store') : $h->url('admin.workflow.update', ['id' => $wf['id']]) ?>">
    <div class="card">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <?php if (!$isNew): ?><input type="hidden" name="version" value="<?= (int) $wf['version'] ?>"><?php endif ?>
        <div class="card__body form">
            <div class="form form--2">
                <div class="<?= $cls('code') ?>"><label for="wf-code"><?= $h->t('Code') ?></label><input type="text" id="wf-code" name="code" value="<?= $h->e($v('code')) ?>" <?= $isNew ? 'required' : 'readonly' ?> placeholder="document.review"><?= $err('code') ?></div>
                <div class="<?= $cls('name') ?>"><label for="wf-name"><?= $h->t('Name') ?></label><input type="text" id="wf-name" name="name" value="<?= $h->e($v('name')) ?>" required><?= $err('name') ?></div>
                <div class="<?= $cls('entity_type') ?>"><label for="wf-entity"><?= $h->t('Entity type') ?></label><input type="text" id="wf-entity" name="entity_type" value="<?= $h->e($v('entity_type')) ?>" <?= $isNew ? 'required' : 'readonly' ?> placeholder="document"><?= $err('entity_type') ?></div>
                <label class="check"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>> <?= $h->t('Enabled') ?></label>
            </div>
            <div class="<?= $cls('definition') ?>"><label for="wf-def"><?= $h->t('Definition (JSON)') ?></label>
                <textarea class="code" id="wf-def" name="definition" rows="24" spellcheck="false" hx-post="<?= $h->url('admin.workflow.validate') ?>" hx-trigger="input changed delay:500ms" hx-target="#validation-report" hx-swap="outerHTML"><?= $h->e($v('definition')) ?></textarea>
                <div class="field__hint"><code><?= $h->e('{"states":[…], "initial":…, "transitions":{"name":{"from":[…], "to":…, "permission"?, "label"?, "approval"?:{"permission", "required_approvals", "expires_hours"?}}}}') ?></code></div></div>
        </div>
        <div class="card__foot">
            <a class="btn btn--ghost" data-back href="<?= $isNew ? $h->url('admin.workflow') : $h->url('admin.workflow.show', ['id' => $wf['id']]) ?>"><?= $h->t('Cancel') ?></a>
            <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
        </div>
    </div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Validation report') ?></h2></div><div class="card__body"><?= $this->render('./_report', ['report' => $report]) ?></div></div>
</form>
