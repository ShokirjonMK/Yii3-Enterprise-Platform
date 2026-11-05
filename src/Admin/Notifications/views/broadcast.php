<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $values
 * @var array<string,list<string>> $errors
 * @var array{users:int, notifications:int}|null $result
 * @var list<string> $roles
 * @var list<array<string,mixed>> $channels
 * @var string $csrf
 */
$this->setTitle($h->tr('Broadcast'));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $h->tr('Broadcast')]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$selected = (array) $v('channels', []);
?>
<?= $this->render('./_tabs', ['active' => 'broadcast']) ?>
<?php if ($result !== null): ?><div class="alert alert--success" data-broadcast-result><?= $h->t('Broadcast sent to {u} users ({n} notifications created).', ['u' => $result['users'], 'n' => $result['notifications']]) ?></div><?php endif ?>
<form class="card" method="post" action="<?= $h->url('admin.notifications.broadcast.send') ?>" data-confirm="<?= $h->t('Send this notification to every active user of the selected role?') ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form form--2">
        <div class="field<?= isset($errors['role']) ? ' field--invalid' : '' ?>"><label for="b-role"><?= $h->t('Role') ?></label>
            <select id="b-role" name="role"><?php foreach ($roles as $r): ?><option value="<?= $h->e($r) ?>"<?= $v('role') === $r ? ' selected' : '' ?>><?= $h->e($r) ?></option><?php endforeach ?></select>
            <div class="field__hint"><?= $h->t('Users holding the role directly or through a parent role; own tenant only without tenants.cross.') ?></div><?= $err('role') ?></div>
        <div class="field<?= isset($errors['template_code']) ? ' field--invalid' : '' ?>"><label for="b-tpl"><?= $h->t('Template code (optional)') ?></label>
            <input type="text" id="b-tpl" name="template_code" value="<?= $h->e($v('template_code')) ?>" placeholder="platform.broadcast"><?= $err('template_code') ?></div>
        <div class="field span-2"><label for="b-subj"><?= $h->t('Subject') ?></label><input type="text" id="b-subj" name="subject" value="<?= $h->e($v('subject')) ?>" maxlength="200"></div>
        <div class="field span-2<?= isset($errors['message']) ? ' field--invalid' : '' ?>"><label for="b-msg"><?= $h->t('Message') ?></label><textarea id="b-msg" name="message" rows="5" maxlength="5000"><?= $h->e($v('message')) ?></textarea><?= $err('message') ?></div>
        <fieldset class="field span-2<?= isset($errors['channels']) ? ' field--invalid' : '' ?>"><legend class="label"><?= $h->t('Channels') ?></legend>
            <div class="row"><?php foreach ($channels as $c): ?><label class="check"><input type="checkbox" name="channels[]" value="<?= $h->e($c['code']) ?>"<?= in_array($c['code'], $selected, true) ? ' checked' : '' ?>> <?= $h->e($c['name']) ?><?= $c['enabled'] ? '' : ' <span class="small muted">(' . $h->t('disabled — will be skipped') . ')</span>' ?></label><?php endforeach ?></div><?= $err('channels') ?></fieldset>
    </div>
    <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('bell') ?> <?= $h->t('Send broadcast') ?></button></div>
</form>
