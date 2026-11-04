<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $code
 * @var list<array{key:string,label:string,type:string,value:mixed,is_set:bool,source:string}> $fields
 * @var array<string, list<string>> $errors
 * @var string $csrf
 * @var callable(string):bool $can
 */

$names = ['email' => 'E-mail (SMTP)', 'telegram' => 'Telegram Bot', 'sms' => 'SMS gateway', 'webpush' => 'Web Push (VAPID)'];
$help = [
    'email' => 'Example: smtp://user:password@smtp.example.com:587 — or smtps://…:465. The sender address must be allowed by your SMTP server.',
    'telegram' => 'Create a bot with @BotFather and paste its token. Alert chat id: the group/channel id that receives platform alerts (e.g. -1001234567890). Users receive personal messages after linking their own chat id in their profile.',
    'sms' => 'HTTPS endpoint of your SMS provider. The platform POSTs JSON {"to","text","from"} with "Authorization: Bearer <token>".',
    'webpush' => 'Browsers need a VAPID key pair. Generate one here; the public key is given to browsers, the private key stays encrypted on the server.',
];
$this->setTitle($h->tr('{name} settings', ['name' => $names[$code] ?? $code]));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $names[$code] ?? $code]);
$manage = $can('notifications.manage');
?>
<?= $this->render('./_tabs', ['active' => 'channels']) ?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('{name} settings', ['name' => $names[$code] ?? $code]) ?></h1>
        <p class="page-head__sub"><?= $h->t($help[$code] ?? '') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.notifications') ?>"><?= $h->icon('bell') ?> <?= $h->t('All channels') ?></a>
    </div>
</div>

<form class="card" method="post" action="<?= $h->url('admin.notifications.settings.save', ['code' => $code]) ?>" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form">
        <?php foreach ($fields as $f):
            $name = str_replace('.', '_', $f['key']);
            $env = $f['source'] === 'env';
            $err = $errors[$name] ?? []; ?>
            <div class="field<?= $err ? ' field--invalid' : '' ?>">
                <label for="<?= $h->e($name) ?>"><?= $h->t($f['label']) ?>
                    <?php if ($f['type'] === 'secret'): ?> <?= $f['is_set'] ? $h->badge('ok', $h->tr('set')) : $h->badge('muted', $h->tr('not set')) ?><?php endif ?>
                    <?php if ($env): ?> <?= $h->badge('info', $h->tr('set by environment')) ?><?php endif ?>
                </label>
                <?php if ($f['type'] === 'secret'): ?>
                    <input id="<?= $h->e($name) ?>" name="<?= $h->e($name) ?>" type="password" autocomplete="new-password" placeholder="<?= $f['is_set'] ? $h->tr('•••••••• (leave empty to keep the current value)') : '' ?>"<?= ($env || !$manage) ? ' disabled' : '' ?>>
                    <?php if ($f['is_set'] && !$env && $manage): ?>
                        <label class="check small"><input type="checkbox" name="clear[]" value="<?= $h->e($f['key']) ?>"> <?= $h->t('Remove stored value') ?></label>
                    <?php endif ?>
                <?php else: ?>
                    <input id="<?= $h->e($name) ?>" name="<?= $h->e($name) ?>" type="text" value="<?= $h->e($f['value']) ?>"<?= ($env || !$manage) ? ' disabled' : '' ?>>
                <?php endif ?>
                <?php if ($env): ?><div class="field__hint"><?= $h->t('The environment variable overrides this field; change it in .env / Kubernetes Secret.') ?></div><?php endif ?>
                <?php foreach ($err as $e): ?><div class="field__error"><?= $h->t($e) ?></div><?php endforeach ?>
            </div>
        <?php endforeach ?>
        <p class="small muted"><?= $h->icon('lock', 'icon--sm') ?> <?= $h->t('Secrets are stored encrypted and are never displayed again. Every change is versioned and written to the audit log.') ?></p>
    </div>
    <?php if ($manage): ?>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save settings') ?></button>
        </div>
    <?php endif ?>
</form>

<?php if ($code === 'webpush' && $manage): ?>
    <form class="card mt" method="post" action="<?= $h->url('admin.notifications.settings.vapid') ?>" data-confirm="<?= $h->t('Generate a new VAPID key pair? Existing browser subscriptions will stop working.') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <div class="card__body row row--between">
            <span><?= $h->t('Generate VAPID keys automatically') ?></span>
            <button class="btn" type="submit"><?= $h->icon('key') ?> <?= $h->t('Generate key pair') ?></button>
        </div>
    </form>
<?php endif ?>
