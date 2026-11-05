<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array> $themes
 * @var array{theme:string, allow_user_choice:bool, tenants:array<string,string>} $settings
 * @var array<string,string> $tenants
 * @var string|null $preview
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Appearance'));
$this->setParameter('breadcrumbs', [$h->tr('System Settings') => $h->url('admin.settings'), $h->tr('Appearance')]);
$manage = $can('settings.manage');
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$c = static fn(array $t, string $k, string $d): string => preg_match('/^#[0-9a-f]{3,8}$/', (string) ($t['colors'][$k] ?? '')) ? (string) $t['colors'][$k] : $d;
?>
<?= $this->render('./_tabs', ['active' => 'appearance']) ?>

<div class="alert alert--info"><?= $h->icon('info') ?> <div><?= $h->t('Pick a ready-made design theme in one click. Preview shows it only to you; Activate applies it to everyone. To make your own: download the starter template, change the colours in theme.css and upload the .zip.') ?></div></div>

<div class="grid grid--3 mb">
<?php foreach ($themes as $code => $t):
    $active = $settings['theme'] === $code; ?>
    <div class="card theme-card<?= $active ? ' theme-card--active' : '' ?>">
        <svg class="theme-card__preview" width="100%" viewBox="0 0 240 120" preserveAspectRatio="xMidYMid meet" role="img" aria-label="<?= $h->t('Preview of {name}', ['name' => $t['name']]) ?>">
            <rect width="240" height="120" rx="8" fill="<?= $h->e($c($t, 'background', '#f5f7fb')) ?>"/>
            <rect width="56" height="120" rx="8" fill="<?= $h->e($c($t, 'sidebar', '#0f1729')) ?>"/>
            <rect x="12" y="14" width="32" height="6" rx="3" fill="#ffffff" opacity=".7"/>
            <rect x="12" y="30" width="28" height="4" rx="2" fill="#ffffff" opacity=".35"/>
            <rect x="12" y="40" width="30" height="4" rx="2" fill="<?= $h->e($c($t, 'primary', '#2f5bea')) ?>"/>
            <rect x="12" y="50" width="24" height="4" rx="2" fill="#ffffff" opacity=".35"/>
            <rect x="70" y="14" width="156" height="40" rx="6" fill="<?= $h->e($c($t, 'surface', '#ffffff')) ?>"/>
            <rect x="80" y="24" width="70" height="6" rx="3" fill="#7b8597" opacity=".6"/>
            <rect x="80" y="36" width="40" height="10" rx="4" fill="<?= $h->e($c($t, 'primary', '#2f5bea')) ?>"/>
            <rect x="70" y="64" width="74" height="44" rx="6" fill="<?= $h->e($c($t, 'surface', '#ffffff')) ?>"/>
            <rect x="152" y="64" width="74" height="44" rx="6" fill="<?= $h->e($c($t, 'dark_background', '#0d1220')) ?>"/>
            <rect x="162" y="76" width="40" height="8" rx="4" fill="<?= $h->e($c($t, 'dark_primary', '#6c8cff')) ?>"/>
        </svg>
        <div class="card__body">
            <h3><?= $h->e($t['name']) ?>
                <?= $active ? $h->badge('ok', $h->tr('active')) : '' ?>
                <?= $preview === $code ? $h->badge('info', $h->tr('preview')) : '' ?>
                <?= $t['builtin'] ? $h->badge('muted', $h->tr('built-in')) : $h->badge('info', $h->tr('uploaded')) ?>
            </h3>
            <p class="small muted"><?= $h->e($t['description']) ?></p>
            <p class="small muted"><code><?= $h->e($code) ?></code> · v<?= $h->e($t['version']) ?><?= $t['author'] !== '' ? ' · ' . $h->e($t['author']) : '' ?><?= $t['dark'] ? ' · ' . $h->t('light + dark') : '' ?></p>
            <div class="btn-group">
                <?php if (!$active && $manage): ?>
                    <form method="post" action="<?= $h->url('admin.appearance.activate') ?>"><?= $csrfField ?><input type="hidden" name="theme" value="<?= $h->e($code) ?>"><button class="btn btn--primary btn--sm" type="submit"><?= $h->icon('check') ?> <?= $h->t('Activate') ?></button></form>
                <?php endif ?>
                <?php if ($preview !== $code): ?>
                    <form method="post" action="<?= $h->url('admin.appearance.preview', ['code' => $code]) ?>"><?= $csrfField ?><button class="btn btn--sm" type="submit"><?= $h->icon('eye') ?> <?= $h->t('Preview') ?></button></form>
                <?php endif ?>
                <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.appearance.download', ['code' => $code]) ?>"><?= $h->icon('download') ?> .zip</a>
                <?php if (!$t['builtin'] && !$active && $manage): ?>
                    <form method="post" action="<?= $h->url('admin.appearance.delete', ['code' => $code]) ?>" data-confirm="<?= $h->t('Delete this theme?') ?>"><?= $csrfField ?><button class="btn btn--sm btn--danger" type="submit" aria-label="<?= $h->t('Delete') ?>"><?= $h->icon('trash', 'icon--sm') ?></button></form>
                <?php endif ?>
            </div>
        </div>
    </div>
<?php endforeach ?>
</div>

<div class="grid grid--3">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Install a theme') ?></h2></div>
        <div class="card__body">
            <ol class="small">
                <li><a href="<?= $h->url('admin.appearance.download', ['code' => 'default'], ['starter' => 1]) ?>"><?= $h->t('Download the starter template') ?></a></li>
                <li><?= $h->t('Edit theme.json (code, name) and the colours in theme.css.') ?></li>
                <li><?= $h->t('Upload the .zip here, preview and activate.') ?></li>
            </ol>
            <?php if ($manage): ?>
            <form method="post" action="<?= $h->url('admin.appearance.upload') ?>" enctype="multipart/form-data" class="form">
                <?= $csrfField ?>
                <div class="field"><label for="theme-file"><?= $h->t('Theme archive (.zip, max 512 KB)') ?></label><input id="theme-file" type="file" name="theme" accept=".zip,application/zip" required></div>
                <label class="check small"><input type="checkbox" name="replace" value="1"> <?= $h->t('Replace an uploaded theme with the same code') ?></label>
                <button class="btn btn--primary" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Upload') ?></button>
            </form>
            <?php endif ?>
            <p class="small muted mt"><?= $h->t('CLI') ?>: <code>php yii theme:make my-brand --from=ocean</code>, <code>php yii theme:install file.zip</code></p>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Personal themes') ?></h2></div>
        <div class="card__body">
            <p class="small muted"><?= $h->t('When allowed, every user can pick a theme in Account → Appearance; otherwise everyone sees the organisation theme.') ?></p>
            <form method="post" action="<?= $h->url('admin.appearance.user_choice') ?>"><?= $csrfField ?>
                <input type="hidden" name="allow" value="<?= $settings['allow_user_choice'] ? '0' : '1' ?>">
                <?= $h->badge($settings['allow_user_choice'] ? 'enabled' : 'disabled') ?>
                <?php if ($manage): ?><button class="btn btn--sm" type="submit"><?= $h->t($settings['allow_user_choice'] ? 'Disallow' : 'Allow') ?></button><?php endif ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Tenant themes') ?></h2></div>
        <div class="card__body">
            <?php if ($settings['tenants'] !== []): ?>
                <ul class="small">
                <?php foreach ($settings['tenants'] as $tid => $tcode): ?>
                    <li><?= $h->e($tenants[$tid] ?? $tid) ?> → <code><?= $h->e($tcode) ?></code></li>
                <?php endforeach ?>
                </ul>
            <?php endif ?>
            <?php if ($manage && $tenants !== []): ?>
            <form method="post" action="<?= $h->url('admin.appearance.tenant') ?>" class="form"><?= $csrfField ?>
                <div class="field"><label for="tenant"><?= $h->t('Tenant') ?></label>
                    <select id="tenant" name="tenant_id"><?php foreach ($tenants as $id => $name): ?><option value="<?= $h->e($id) ?>"><?= $h->e($name) ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="tenant-theme"><?= $h->t('Theme') ?></label>
                    <select id="tenant-theme" name="theme"><option value=""><?= $h->t('— follow the global theme —') ?></option><?php foreach ($themes as $code => $t): ?><option value="<?= $h->e($code) ?>"><?= $h->e($t['name']) ?></option><?php endforeach ?></select></div>
                <button class="btn btn--sm" type="submit"><?= $h->t('Save') ?></button>
            </form>
            <?php endif ?>
        </div>
    </div>
</div>
