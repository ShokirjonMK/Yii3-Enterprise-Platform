<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array|null $row
 * @var array $values
 * @var array<string,list<string>> $errors
 * @var list<string> $channels
 * @var list<string> $locales
 * @var callable(string):bool $can
 * @var string $csrf
 */
$isNew = $row === null;
$manage = $can('notifications.manage');
$this->setTitle($isNew ? $h->tr('New template') : $h->tr('Template {code}', ['code' => $row['code']]));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $h->tr('Templates') => $h->url('admin.notifications.templates'), $isNew ? $h->tr('New') : $row['code'] . ' · ' . $row['channel'] . ' · ' . $row['locale']]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$cls = static fn(string $k): string => isset($errors[$k]) ? 'field field--invalid' : 'field';
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $isNew ? $h->t('New template') : $h->t('Edit template') ?></h1>
    <p class="page-head__sub"><?= $h->t('Placeholders') ?>: <code>{name}</code> (<?= $h->t('recipient') ?>), <?= $h->t('plus any parameter passed by the sender, e.g.') ?> <code>{project}</code>, <code>{status}</code>.</p></div></div>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>
<div class="grid grid--2">
    <form class="card" id="template-form" method="post" action="<?= $isNew ? $h->url('admin.notifications.templates.store') : $h->url('admin.notifications.templates.update', ['id' => $row['id']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <div class="card__body form">
            <div class="<?= $cls('code') ?>"><label for="t-code"><?= $h->t('Code') ?></label><input type="text" id="t-code" name="code" value="<?= $h->e($v('code')) ?>" required><?= $err('code') ?></div>
            <div class="form form--2">
                <div class="<?= $cls('channel') ?>"><label for="t-ch"><?= $h->t('Channel') ?></label><select id="t-ch" name="channel"><?php foreach ($channels as $c): ?><option value="<?= $h->e($c) ?>"<?= $v('channel') === $c ? ' selected' : '' ?>><?= $h->e($c) ?></option><?php endforeach ?></select><?= $err('channel') ?></div>
                <div class="<?= $cls('locale') ?>"><label for="t-loc"><?= $h->t('Locale') ?></label><select id="t-loc" name="locale"><?php foreach ($locales as $l): ?><option value="<?= $l ?>"<?= $v('locale') === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select><?= $err('locale') ?></div>
            </div>
            <div class="<?= $cls('subject') ?>"><label for="t-subj"><?= $h->t('Subject') ?></label><input type="text" id="t-subj" name="subject" value="<?= $h->e($v('subject')) ?>" maxlength="255"><?= $err('subject') ?></div>
            <div class="<?= $cls('body') ?>"><label for="t-body"><?= $h->t('Body (plain text)') ?></label><textarea class="code" id="t-body" name="body" rows="10" required><?= $h->e($v('body')) ?></textarea><?= $err('body') ?></div>
            <div class="field"><label for="t-sample"><?= $h->t('Sample parameters for preview (JSON)') ?></label><textarea class="code" id="t-sample" name="sample" rows="3">{"project": "Alpha", "status": "active", "actor": "admin"}</textarea></div>
        </div>
        <div class="card__foot">
            <button class="btn" type="button" hx-post="<?= $h->url('admin.notifications.templates.preview') ?>" hx-include="#template-form" hx-target="#preview" hx-swap="innerHTML"><?= $h->icon('eye') ?> <?= $h->t('Preview') ?></button>
            <?php if ($manage): ?><button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button><?php endif ?>
        </div>
    </form>
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('Preview') ?></h2></div>
            <div id="preview" hx-post="<?= $h->url('admin.notifications.templates.preview') ?>" hx-include="#template-form" hx-trigger="load, input from:#template-form delay:600ms" hx-swap="innerHTML"><div class="card__body muted"><?= $h->t('Rendering…') ?></div></div>
        </div>
        <?php if (!$isNew && $manage): ?>
        <form class="card" method="post" action="<?= $h->url('admin.notifications.templates.delete', ['id' => $row['id']]) ?>" data-confirm="<?= $h->t('Delete this template? Notifications fall back to the English / in-app version.') ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="card__body row row--between"><span class="small muted"><?= $h->t('Version {v} · updated {ago}', ['v' => $row['version'], 'ago' => $h->ago($row['updated_at'])]) ?></span>
                <button class="btn btn--danger btn--sm" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></div>
        </form>
        <?php endif ?>
    </div>
</div>
