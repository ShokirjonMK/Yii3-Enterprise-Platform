<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string, list<array{key:string,name:string,label:string,type:string,options:list<string>,min:?int,max:?int,value:mixed,is_set:bool,source:string,critical:bool}>> $sections
 * @var array<string, list<string>> $errors
 * @var string $csrf
 * @var callable(string):bool $can
 * @var string|null $page   'sessions' = Sessions & sign-in page (same form, other settings)
 */

$page ??= null;
$isSessions = $page === 'sessions';
$this->setTitle($h->tr($isSessions ? 'Sessions & sign-in' : 'Two-factor settings'));
$this->setParameter('breadcrumbs', $isSessions
    ? [$h->tr('Authentication') => $h->url('admin.auth'), $h->tr('Sessions & sign-in')]
    : [$h->tr('Authentication') => $h->url('admin.auth'), $h->tr('Two-factor') => $h->url('admin.auth.mfa'), $h->tr('Settings')]);
$manage = $can('auth.manage');
$optionLabels = ['' => '— none —', 'twilio' => 'Twilio Programmable Voice', 'http' => 'HTTPS voice gateway (JSON)', 'polling' => 'Polling (no public URL needed)', 'webhook' => 'Webhook (public HTTPS)'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Authentication') ?></h1>
        <p class="page-head__sub"><?= $isSessions ? $h->t('How long a sign-in lasts, how often critical actions ask for the password again, and the lockout after failed passwords. Changes apply to the next request.') : $h->t('One-time codes, voice calls, E-IMZO and passkeys. Telegram bot token, SMTP and SMS gateway are set in Notifications.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => $isSessions ? 'admin.auth.sessions' : 'admin.auth.mfa.settings']) ?>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->icon('alert') ?> <div><?= $h->t('Some values were not saved — see the highlighted fields.') ?></div></div><?php endif ?>

<form method="post" action="<?= $h->url($isSessions ? 'admin.auth.sessions.save' : 'admin.auth.mfa.settings.save') ?>" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <?php foreach ($sections as $title => $fields): ?>
    <div class="card mb">
        <div class="card__head"><h2><?= $h->t($title) ?></h2></div>
        <div class="card__body form form--grid">
        <?php foreach ($fields as $f):
            $env = $f['source'] === 'env';
            $off = $env || !$manage;
            $err = $errors[$f['name']] ?? []; ?>
            <div class="field<?= $err ? ' field--invalid' : '' ?>">
                <label for="<?= $h->e($f['name']) ?>"><?= $h->t($f['label']) ?>
                    <?php if ($f['type'] === 'secret'): ?> <?= $f['is_set'] ? $h->badge('ok', $h->tr('set')) : $h->badge('muted', $h->tr('not set')) ?><?php endif ?>
                    <?php if ($env): ?> <?= $h->badge('info', $h->tr('set by environment')) ?><?php endif ?>
                </label>
                <?php if ($f['type'] === 'enum'): ?>
                    <select id="<?= $h->e($f['name']) ?>" name="<?= $h->e($f['name']) ?>"<?= $off ? ' disabled' : '' ?>>
                        <?php foreach ($f['options'] as $o): ?><option value="<?= $h->e($o) ?>"<?= (string) $f['value'] === $o ? ' selected' : '' ?>><?= $h->e(isset($optionLabels[$o]) ? $h->tr($optionLabels[$o]) : $o) ?></option><?php endforeach ?>
                    </select>
                <?php elseif ($f['type'] === 'int'): ?>
                    <input id="<?= $h->e($f['name']) ?>" name="<?= $h->e($f['name']) ?>" type="number" value="<?= $h->e($f['value']) ?>"<?= $f['min'] !== null ? ' min="' . (int) $f['min'] . '"' : '' ?><?= $f['max'] !== null ? ' max="' . (int) $f['max'] . '"' : '' ?><?= $off ? ' disabled' : '' ?>>
                <?php elseif ($f['type'] === 'secret'): ?>
                    <input id="<?= $h->e($f['name']) ?>" name="<?= $h->e($f['name']) ?>" type="password" autocomplete="new-password" placeholder="<?= $f['is_set'] ? $h->tr('•••••••• (leave empty to keep the current value)') : '' ?>"<?= $off ? ' disabled' : '' ?>>
                    <?php if ($f['is_set'] && !$off): ?><label class="check small"><input type="checkbox" name="clear[]" value="<?= $h->e($f['key']) ?>"> <?= $h->t('Remove stored value') ?></label><?php endif ?>
                <?php elseif ($f['type'] === 'json'): ?>
                    <textarea id="<?= $h->e($f['name']) ?>" name="<?= $h->e($f['name']) ?>" rows="4" class="mono"<?= $off ? ' disabled' : '' ?>><?= $h->e(json_encode($f['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                <?php else: ?>
                    <input id="<?= $h->e($f['name']) ?>" name="<?= $h->e($f['name']) ?>" type="text" value="<?= $h->e($f['value']) ?>"<?= $off ? ' disabled' : '' ?>>
                <?php endif ?>
                <?php foreach ($err as $e): ?><div class="field__error"><?= $h->t($e) ?></div><?php endforeach ?>
            </div>
        <?php endforeach ?>
        </div>
    </div>
    <?php endforeach ?>
    <?php if ($manage): ?>
        <div class="card"><div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save settings') ?></button>
            <span class="small muted"><?= $h->icon('lock', 'icon--sm') ?> <?= $h->t('Requires re-authentication. Every change is versioned and audited.') ?></span>
        </div></div>
    <?php endif ?>
</form>
