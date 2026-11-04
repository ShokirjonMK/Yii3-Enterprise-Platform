<?php

declare(strict_types=1);

/**
 * Template: admin detail view (resources/views/admin/<module>/view.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$dl = [];
foreach ($m->fields as $f) {
    $dl[] = '                    <dt><?= $h->t(' . $q($f->label()) . ') ?></dt><dd><?= ' . FieldCode::detailValue($f, '$entity') . ' ?></dd>';
}
$status = $m->hasStatus();
$statusEditable = $status ? ' && !$entity->status()->isTerminal()' : '';
$transitions = $status ? <<<'PHP'
            <?php foreach ($transitions as $t): ?>
                <form method="post" action="<?= $h->url('admin.{{code}}.status', ['id' => $entity->id]) ?>">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <input type="hidden" name="version" value="<?= $entity->version() ?>">
                    <input type="hidden" name="status" value="<?= $h->e($t->value) ?>">
                    <button class="btn" type="submit" data-transition="<?= $h->e($t->value) ?>"><?= $h->t($t->label()) ?></button>
                </form>
            <?php endforeach ?>

PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Admin · {{entityLabelLower}} detail: attributes, __ACTIONS_DOC__delete, activity timeline.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Modules\{{Module}}\Domain\{{Entity}} $entity
 * @var array{source:string, items:list<array<string,mixed>>} $activity
 * @var list<object> $transitions
 */

$transitions ??= [];
$this->setTitle($entity->displayTitle());
$this->setParameter('breadcrumbs', [$h->tr('{{label}}') => $h->url('admin.{{code}}'), $entity->displayTitle()]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($entity->displayTitle()) ?></h1>
        <p class="page-head__sub"><?= $h->t('version {v}', ['v' => $entity->version()]) ?> · <span class="mono small"><?= $h->e($entity->id) ?></span></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('{{code}}.update')__EDITABLE__): ?>
            <a class="btn" href="<?= $h->url('admin.{{code}}.edit', ['id' => $entity->id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
__TRANSITIONS__        <?php endif ?>
        <?php if ($can('{{code}}.delete')): ?>
            <form method="post" action="<?= $h->url('admin.{{code}}.delete', ['id' => $entity->id]) ?>" data-confirm="<?= $h->t('Delete this {{entityLabelLower}}?') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <input type="hidden" name="version" value="<?= $entity->version() ?>">
                <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>
<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
__DL__
                    <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($entity->createdAt) ?></dd>
                    <dt><?= $h->t('Updated') ?></dt><dd><?= $h->datetime($entity->updatedAt()) ?></dd>
            </dl>
        </div>
    </div>
    <div class="card" id="{{slug}}-activity">
        <div class="card__head"><h2><?= $h->t('Activity') ?></h2><span class="small muted"><?= $activity['source'] === 'read_model' ? $h->t('Kafka read model') : $h->t('Audit trail') ?></span></div>
        <?php if ($activity['items'] === []): ?>
            <div class="empty"><?= $h->t('No activity yet.') ?></div>
        <?php else: ?>
            <ul class="timeline card__body">
                <?php foreach ($activity['items'] as $a): ?>
                    <li>
                        <div class="row row--between"><code><?= $h->e($a['event_type']) ?></code><span class="small muted"><?= $h->ago($a['occurred_at']) ?></span></div>
                        <div><?= $h->e($a['summary']) ?></div>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</div>

PHP, [
    '__ACTIONS_DOC__' => $status ? 'lifecycle transitions, ' : 'edit, ',
    '__EDITABLE__' => $statusEditable,
    '__TRANSITIONS__' => $transitions,
    '__DL__' => implode("\n", $dl),
]);
