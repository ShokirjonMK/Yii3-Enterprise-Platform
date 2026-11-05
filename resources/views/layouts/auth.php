<?php

declare(strict_types=1);

use App\Shared\Infrastructure\View\PlatformAsset;

/**
 * @var Yiisoft\View\WebView $this
 * @var string $content
 * @var string|null $csrf
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var array $flashes
 * @var array $platform
 * @var array|null $theme active design theme (AppearanceViewInjection)
 */

$assetManager->register(PlatformAsset::class);
$this->addCssFiles($assetManager->getCssFiles());
$this->addJsFiles($assetManager->getJsFiles());
$this->beginPage();
?>
<!DOCTYPE html>
<html lang="<?= $h->e($h->locale()) ?>" data-theme-pack="<?= $h->e($theme['code'] ?? 'default') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true}'>
    <meta name="author" content="<?= $h->e($platform['author']) ?>">
    <meta name="generator" content="Yii3 Enterprise Platform by <?= $h->e($platform['author']) ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title><?= $h->e($this->getTitle()) ?> · <?= $h->e($platform['name']) ?></title>
    <?php $this->head() ?>
    <?php if (!empty($theme['href'])): ?><link rel="stylesheet" href="<?= $h->e($theme['href']) ?>"><?php endif /* design theme (Settings → Appearance) */ ?>
</head>
<body>
<?php $this->beginBody() ?>
<div class="auth">
    <section class="auth__aside" aria-hidden="true">
        <div class="row"><span class="sidebar__logo">Y3</span><strong><?= $h->e($platform['name']) ?></strong></div>
        <div>
            <h1><?= $h->t('Secure access to the enterprise platform') ?></h1>
            <ul>
                <li><?= $h->t('Single sign-on with OneID and corporate identity providers') ?></li>
                <li><?= $h->t('Multi-factor authentication and full audit trail') ?></li>
                <li><?= $h->t('Role-based access control across tenants') ?></li>
            </ul>
        </div>
        <div class="small">© <?= date('Y') ?> <?= $h->e($platform['name']) ?> · Developer <a href="<?= $h->e($platform['authorUrl']) ?>" rel="noopener" target="_blank"><?= $h->e($platform['author']) ?></a> · v<?= $h->e($platform['version']) ?></div>
    </section>
    <section class="auth__panel">
        <div class="auth__card">
            <?php foreach ($flashes as $type => $messages): foreach ((array) $messages as $m): ?>
                <div class="alert alert--<?= $h->e($type) ?>" role="alert"><?= $h->e(is_array($m) ? ($m['body'] ?? '') : $m) ?></div>
            <?php endforeach; endforeach ?>
            <?= $content ?>
            <div class="row mt small muted">
                <?php foreach ($platform['locales'] as $code => $label): ?>
                    <form method="post" action="<?= $h->url('auth.locale') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="locale" value="<?= $h->e($code) ?>"><button type="submit" class="btn btn--ghost btn--sm"><?= $h->e($label) ?></button></form>
                <?php endforeach ?>
                <button type="button" class="btn btn--ghost btn--sm" data-theme-toggle><?= $h->icon('moon', 'icon--sm') ?></button>
            </div>
        </div>
    </section>
</div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
