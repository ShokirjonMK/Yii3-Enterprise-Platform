<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Modules\Projects\Domain\Project|null $project
 * @var array $values
 * @var array $errors
 * @var list<string> $priorities
 */

$editing = $project !== null;
$title = $editing ? $h->tr('Edit project {code}', ['code' => $project->code()]) : $h->tr('New project');
$this->setTitle($title);
$action = $editing ? $h->url('web.projects.update', ['id' => $project->id]) : $h->url('web.projects.store');
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div></div>
<div class="card">
    <div class="card__body">
        <form class="form form--2" method="post" action="<?= $h->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?php if ($editing): ?><input type="hidden" name="version" value="<?= (int) ($values['version'] ?? $project->version()) ?>"><?php endif ?>
            <?= $this->render('//admin/projects/_fields', ['values' => $values, 'errors' => $errors, 'priorities' => $priorities, 'showOwner' => false]) ?>
            <div class="row span-2">
                <button class="btn btn--primary" type="submit"><?= $editing ? $h->t('Save') : $h->t('Create project') ?></button>
                <a class="btn btn--ghost" href="<?= $editing ? $h->url('web.projects.view', ['id' => $project->id]) : $h->url('web.projects') ?>"><?= $h->t('Cancel') ?></a>
            </div>
        </form>
    </div>
</div>
