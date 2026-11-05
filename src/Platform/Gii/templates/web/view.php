<?php

declare(strict_types=1);

/**
 * Template: web detail view (src/Modules/<Module>/Web/views/view.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$dl = [];
foreach ($m->fields as $f) {
    $dl[] = '                <dt><?= $h->t(' . $q($f->label()) . ') ?></dt><dd><?= ' . FieldCode::detailValue($f, '$entity') . ' ?></dd>';
}
$status = $m->hasStatus();
$transitions = $status ? <<<'PHP'
            <?php foreach ($transitions as $t): ?>
                <form method="post" action="<?= $h->url('web.{{code}}.status', ['id' => $entity->id]) ?>">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <input type="hidden" name="version" value="<?= $entity->version() ?>">
                    <input type="hidden" name="status" value="<?= $h->e($t->value) ?>">
                    <button class="btn" type="submit"><?= $h->t($t->label()) ?></button>
                </form>
            <?php endforeach ?>

PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Web · {{entityLabelLower}} detail.
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
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($entity->displayTitle()) ?></h1></div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= $h->url('web.{{code}}') ?>"><?= $h->t('Back to {{labelLower}}') ?></a>
        <?php if ($can('{{code}}.update')__EDITABLE__): ?>
            <a class="btn" href="<?= $h->url('web.{{code}}.edit', ['id' => $entity->id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
__TRANSITIONS__        <?php endif ?>
    </div>
</div>
<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__body">
            <dl class="dl">
__DL__
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Activity') ?></h2></div>
        <?php if ($activity['items'] === []): ?>
            <div class="empty"><?= $h->t('No activity yet.') ?></div>
        <?php else: ?>
            <ul class="timeline card__body">
                <?php foreach ($activity['items'] as $a): ?>
                    <li><div class="row row--between"><span><?= $h->e($a['summary']) ?></span><span class="small muted"><?= $h->ago($a['occurred_at']) ?></span></div></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</div>

PHP, [
    '__EDITABLE__' => $status ? ' && !$entity->status()->isTerminal()' : '',
    '__TRANSITIONS__' => $transitions,
    '__DL__' => implode("\n", $dl),
]);
