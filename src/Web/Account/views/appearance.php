<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array> $themes
 * @var bool $allowed
 * @var string|null $choice
 * @var string $organisation
 * @var string $csrf
 */

$this->setTitle($h->tr('Appearance'));
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$c = static fn(array $t, string $k, string $d): string => preg_match('/^#[0-9a-f]{3,8}$/', (string) ($t['colors'][$k] ?? '')) ? (string) $t['colors'][$k] : $d;
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Appearance') ?></h1>
    <p class="page-head__sub"><?= $h->t('Choose a design theme for yourself. Light / dark mode is switched with the moon button.') ?></p></div></div>

<?php if (!$allowed): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <div><?= $h->t('Your organisation uses one theme for everyone: {name}.', ['name' => $themes[$organisation]['name'] ?? $organisation]) ?></div></div>
<?php else: ?>
<form method="post" action="<?= $h->url('web.appearance.save') ?>">
    <?= $csrfField ?>
    <div class="grid grid--3">
        <label class="card">
            <div class="card__body">
                <span class="check"><input type="radio" name="theme" value=""<?= $choice === null ? ' checked' : '' ?>> <strong><?= $h->t('Organisation theme') ?></strong></span>
                <p class="small muted"><?= $h->e($themes[$organisation]['name'] ?? $organisation) ?></p>
            </div>
        </label>
        <?php foreach ($themes as $code => $t): ?>
        <label class="card">
            <svg width="100%" viewBox="0 0 240 60" role="img" aria-label="<?= $h->e($t['name']) ?>">
                <rect width="240" height="60" fill="<?= $h->e($c($t, 'background', '#f5f7fb')) ?>"/>
                <rect width="48" height="60" fill="<?= $h->e($c($t, 'sidebar', '#0f1729')) ?>"/>
                <rect x="64" y="14" width="80" height="32" rx="5" fill="<?= $h->e($c($t, 'surface', '#ffffff')) ?>"/>
                <rect x="74" y="34" width="34" height="8" rx="3" fill="<?= $h->e($c($t, 'primary', '#2f5bea')) ?>"/>
                <rect x="160" y="14" width="66" height="32" rx="5" fill="<?= $h->e($c($t, 'dark_background', '#0d1220')) ?>"/>
                <rect x="170" y="34" width="30" height="8" rx="3" fill="<?= $h->e($c($t, 'dark_primary', '#6c8cff')) ?>"/>
            </svg>
            <div class="card__body">
                <span class="check"><input type="radio" name="theme" value="<?= $h->e($code) ?>"<?= $choice === $code ? ' checked' : '' ?>> <strong><?= $h->e($t['name']) ?></strong></span>
                <p class="small muted"><?= $h->e($t['description']) ?></p>
            </div>
        </label>
        <?php endforeach ?>
    </div>
    <p class="mt"><button class="btn btn--primary" type="submit"><?= $h->t('Save') ?></button></p>
</form>
<?php endif ?>
