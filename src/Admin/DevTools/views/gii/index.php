<?php

declare(strict_types=1);

/**
 * Admin · Developer Tools · Enterprise Gii: generator form, preview with per-file status / content / diff,
 * apply with confirmation (protected files are overwritten only when ticked).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var array{module:string, fields:string, generators:list<string>, overwrite:list<string>} $values
 * @var list<array{id:string, name:string, description:string, requires:list<string>}> $catalog
 * @var App\Platform\Gii\GiiPlan|null $plan
 * @var array<string, list<string>> $errors
 * @var array|null $nextSteps NextStepsRunner::state() after apply
 */

use App\Platform\Gii\PlannedFile;

$this->setTitle($h->tr('Enterprise Gii'));
$this->setParameter('breadcrumbs', [$h->tr('Developer tools') => $h->urlOrNull('admin.devtools'), $h->tr('Enterprise Gii')]);
$selected = in_array('all', $values['generators'], true) ? array_column($catalog, 'id') : $values['generators'];
$renderDiff = static function (string $diff) use ($h): string {
    $out = '';
    foreach (explode("\n", $diff) as $line) {
        $class = match (true) {
            str_starts_with($line, '+') => 'gii-diff__add',
            str_starts_with($line, '-') => 'gii-diff__del',
            str_starts_with($line, '@@') => 'gii-diff__hunk',
            default => '',
        };
        $out .= '<span class="' . $class . '">' . $h->e($line) . '</span>';
    }
    return $out;
};
$hidden = static function () use ($h, $values, $selected): string {
    $html = '<input type="hidden" name="module" value="' . $h->e($values['module']) . '">'
        . '<input type="hidden" name="fields" value="' . $h->e($values['fields']) . '">';
    foreach ($selected as $g) {
        $html .= '<input type="hidden" name="generators[]" value="' . $h->e($g) . '">';
    }
    return $html;
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Enterprise Gii') ?></h1>
        <p class="page-head__sub"><?= $h->t('Generates fully integrated business modules (Projects structure) on top of yiisoft/yii-gii. Dry run first; nothing is written without confirmation.') ?></p>
    </div>
    <div class="page-head__actions"><a class="btn" href="<?= $h->url('admin.devtools.gii.steps') ?>"><?= $h->icon('play') ?> <?= $h->t('Next steps') ?></a></div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert--error" role="alert">
        <ul class="gii-errors">
            <?php foreach ($errors as $key => $messages): ?>
                <?php foreach ($messages as $msg): ?><li><span class="mono"><?= $h->e($key) ?></span>: <?= $h->e($msg) ?></li><?php endforeach ?>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Module') ?></h2></div>
    <div class="card__body">
        <form class="form form--2" method="post" action="<?= $h->url('admin.devtools.gii.preview') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field<?= isset($errors['module']) ? ' field--invalid' : '' ?>">
                <label for="gii-module"><?= $h->t('Module name') ?></label>
                <input id="gii-module" type="text" name="module" required pattern="[A-Z][A-Za-z0-9]{2,40}" maxlength="41" value="<?= $h->e($values['module']) ?>" placeholder="Contracts" autocomplete="off">
                <div class="field__hint"><?= $h->t('PascalCase plural: namespace App\Modules\<Name>, entity = singular, table = snake_case.') ?></div>
            </div>
            <div class="field span-2<?= isset($errors['fields']) ? ' field--invalid' : '' ?>">
                <label for="gii-fields"><?= $h->t('Fields') ?></label>
                <textarea id="gii-fields" name="fields" rows="3" class="mono" required><?= $h->e($values['fields']) ?></textarea>
                <div class="field__hint"><?= $h->t('name:type[:modifier]… separated by commas. Types: string text int decimal bool date datetime email enum(a|b) user uuid. Modifiers: required unique index max=N min=N default=V. An enum named "status" is the lifecycle.') ?></div>
            </div>
            <fieldset class="span-2 gii-generators">
                <legend><?= $h->t('Generators') ?></legend>
                <?php foreach ($catalog as $g): ?>
                    <?php if ($g['id'] === 'module') { continue; } ?>
                    <label class="gii-generator">
                        <input type="checkbox" name="generators[]" value="<?= $h->e($g['id']) ?>"<?= in_array($g['id'], $selected, true) ? ' checked' : '' ?>>
                        <span><strong><?= $h->e($g['name']) ?></strong> <code><?= $h->e($g['id']) ?></code>
                            <span class="small muted"><?= $h->e($g['description']) ?><?= $g['requires'] !== [] ? ' ' . $h->t('Requires:') . ' ' . $h->e(implode(', ', $g['requires'])) : '' ?></span></span>
                    </label>
                <?php endforeach ?>
            </fieldset>
            <div class="row span-2">
                <button class="btn btn--primary" type="submit"><?= $h->icon('eye') ?> <?= $h->t('Preview (dry run)') ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($plan !== null): ?>
    <?php $counts = $plan->counts(); ?>
    <div class="card mt" id="gii-preview">
        <div class="card__head">
            <h2><?= $plan->applied ? $h->t('Result') : $h->t('Preview') ?> · <span class="mono"><?= $h->e($plan->module) ?></span></h2>
            <span class="small muted">
                <?= $h->t('{n} files', ['n' => count($plan->files)]) ?>:
                <?php foreach ($counts as $status => $n): ?><span class="tag gii-status gii-status--<?= $h->e($status) ?>"><?= $h->e($status) ?> <?= (int) $n ?></span> <?php endforeach ?>
            </span>
        </div>
        <form method="post" action="<?= $h->url('admin.devtools.gii.apply') ?>" data-confirm="<?= $h->t('Write the generated files into the project?') ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?= $hidden() ?>
            <div class="gii-files">
                <?php foreach ($plan->files as $f): ?>
                    <details class="gii-file gii-file--<?= $h->e($f->status) ?>">
                        <summary>
                            <span class="tag gii-status gii-status--<?= $h->e($f->status) ?>"><?= $h->e($f->status) ?></span>
                            <span class="mono"><?= $h->e($f->path()) ?></span>
                            <span class="small muted"><?= $h->e($f->generator) ?></span>
                            <?php if ($f->result !== null): ?><span class="tag"><?= $h->e($f->result) ?></span><?php endif ?>
                            <?php if ($f->error !== null): ?><span class="badge badge--bad"><?= $h->t('error') ?></span><?php endif ?>
                        </summary>
                        <?php if (in_array($f->status, [PlannedFile::SKIP, PlannedFile::OVERWRITE], true) && !$plan->applied): ?>
                            <label class="row small gii-overwrite"><input type="checkbox" name="overwrite[]" value="<?= $h->e($f->path()) ?>"<?= in_array($f->path(), $values['overwrite'], true) ? ' checked' : '' ?>> <?= $h->t('Overwrite this existing file') ?></label>
                        <?php endif ?>
                        <?php if ($f->exists() && $f->status !== PlannedFile::UNCHANGED): ?>
                            <div class="small muted"><?= $h->t('Unified diff (current → generated)') ?></div>
                            <pre class="gii-code gii-diff"><?= $renderDiff($f->diff()) ?></pre>
                        <?php else: ?>
                            <pre class="gii-code"><?= $h->e($f->content()) ?></pre>
                        <?php endif ?>
                        <?php if ($f->error !== null): ?><pre class="gii-code gii-diff__del"><?= $h->e($f->error) ?></pre><?php endif ?>
                    </details>
                <?php endforeach ?>
            </div>
            <?php if (!$plan->applied): ?>
                <div class="card__body row">
                    <label class="row small"><input type="checkbox" name="confirm" value="1" required> <?= $h->t('I reviewed the preview') ?></label>
                    <button class="btn btn--danger" type="submit"><?= $h->icon('check') ?> <?= $h->t('Apply') ?></button>
                    <span class="small muted"><?= $h->t('New files are created; protected files are kept unless ticked; unchanged files are skipped.') ?></span>
                </div>
            <?php endif ?>
        </form>
    </div>
    <?php if ($plan->applied && $nextSteps !== null): ?>
        <div class="card mt" id="gii-next-steps">
            <div class="card__head">
                <h2><?= $h->t('Next steps') ?></h2>
                <a class="small" href="<?= $h->url('admin.devtools.gii.steps') ?>"><?= $h->t('History') ?></a>
            </div>
            <div class="card__body">
                <?= $this->render('./_steps-form', [
                    'state' => $nextSteps,
                    'module' => $plan->module,
                    'csrf' => $csrf,
                    'selected' => array_values(array_filter(['migrate', 'rbac', in_array('event', $plan->generators, true) && $nextSteps['kafka_enabled'] ? 'kafka' : null])),
                ]) ?>
            </div>
        </div>
    <?php endif ?>
<?php endif ?>
