<?php

declare(strict_types=1);

/**
 * Template: admin form view (resources/views/admin/<module>/form.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Admin · create / edit {{entityLabelLower}}.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Modules\{{Module}}\Domain\{{Entity}}|null $entity
 * @var array<string,mixed> $values
 * @var array<string, list<string>> $errors
 */

$editing = $entity !== null;
$title = $editing ? $h->tr('Edit {{entityLabelLower}} {title}', ['title' => $entity->displayTitle()]) : $h->tr('New {{entityLabelLower}}');
$this->setTitle($title);
$this->setParameter('breadcrumbs', array_filter([
    $h->tr('{{label}}') => $h->url('admin.{{code}}'),
    ...($editing ? [$entity->displayTitle() => $h->url('admin.{{code}}.view', ['id' => $entity->id])] : []),
    $title,
]));
$action = $editing ? $h->url('admin.{{code}}.update', ['id' => $entity->id]) : $h->url('admin.{{code}}.store');
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div>
</div>
<div class="card">
    <div class="card__body">
        <form class="form form--2" method="post" action="<?= $h->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?php if ($editing): ?><input type="hidden" name="version" value="<?= (int) ($values['version'] ?? $entity->version()) ?>"><?php endif ?>
            <?= $this->render('./_fields', ['values' => $values, 'errors' => $errors]) ?>
            <div class="row span-2">
                <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save') : $h->t('Create') ?></button>
                <a class="btn btn--ghost" href="<?= $editing ? $h->url('admin.{{code}}.view', ['id' => $entity->id]) : $h->url('admin.{{code}}') ?>"><?= $h->t('Cancel') ?></a>
            </div>
        </form>
    </div>
</div>

PHP);
