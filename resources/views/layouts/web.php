<?php

declare(strict_types=1);

use App\Shared\Infrastructure\View\PlatformAsset;

/**
 * Public / end-user layout.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $content
 * @var string|null $csrf
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var App\Platform\Identity\Domain\Identity|null $identity
 * @var callable(string):bool $can
 * @var bool $isHtmx
 * @var array $flashes
 * @var array $platform
 * @var array|null $theme active design theme (AppearanceViewInjection)
 */

$title = $this->getTitle() ?: $platform['name'];
$flashHtml = '';
foreach ($flashes as $type => $messages) {
    foreach ((array) $messages as $m) {
        $text = is_array($m) ? ($m['body'] ?? '') : $m;
        $flashHtml .= '<div data-flash="' . $h->e($type) . '" data-flash-message="' . $h->e($text) . '" class="alert alert--' . $h->e($type) . '">' . $h->e($text) . '</div>';
    }
}
$main = '<main id="main" class="web-main" tabindex="-1">' . ($isHtmx ? '<title>' . $h->e($title) . '</title>' : '') . $flashHtml . $content . '</main>';

if ($isHtmx) {
    echo $main;
    return;
}

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
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"historyCacheSize":0,"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true}'>
    <meta name="author" content="<?= $h->e($platform['author']) ?>">
    <meta name="generator" content="Yii3 Enterprise Platform by <?= $h->e($platform['author']) ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title><?= $h->e($title) ?></title>
    <?php $this->head() ?>
    <?php if (!empty($theme['href'])): ?><link rel="stylesheet" href="<?= $h->e($theme['href']) ?>"><?php endif /* design theme (Settings → Appearance) */ ?>
</head>
<body class="page-sticky" hx-headers='{"X-CSRF-Token":"<?= $h->e($csrf) ?>"}'>
<?php $this->beginBody() ?>
<a class="skip-link" href="#main"><?= $h->t('Skip to content') ?></a>
<div class="htmx-indicator progress-bar" id="global-progress" aria-hidden="true"></div>
<header class="web-top">
    <div class="web-top__inner">
        <a href="/" class="row"><span class="sidebar__logo">Y3</span><strong><?= $h->e($platform['name']) ?></strong></a>
        <nav class="web-nav" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-indicator="#global-progress">
            <a href="<?= $h->url('web.home') ?>"<?= $h->isRoute('web.home') ? ' aria-current="page"' : '' ?>><?= $h->t('Home') ?></a>
            <?php if ($identity): ?>
                <?php if ($can('projects.view') && $feature('admin.projects')): ?><a href="<?= $h->url('web.projects') ?>"<?= $h->isRoute('web.projects') ? ' aria-current="page"' : '' ?>><?= $h->t('Projects') ?></a><?php endif ?>
                <a href="<?= $h->url('web.notifications') ?>"<?= $h->isRoute('web.notifications') ? ' aria-current="page"' : '' ?>><?= $h->t('Notifications') ?></a>
                <a href="<?= $h->url('web.profile') ?>"<?= $h->isRoute('web.profile', 'web.security', 'web.appearance', 'web.login_history') ? ' aria-current="page"' : '' ?>><?= $h->t('Profile') ?></a>
            <?php endif ?>
            <a href="/api/docs" hx-boost="false"><?= $h->t('API') ?></a>
        </nav>
        <div class="topbar__spacer"></div>
        <button type="button" class="btn btn--ghost btn--icon" data-theme-toggle aria-label="<?= $h->t('Toggle dark mode') ?>"><?= $h->icon('moon') ?></button>
        <?php if ($identity): ?>
            <?php if ($can('admin.access')): ?><a class="btn btn--sm" href="<?= $h->url('admin.dashboard') ?>"><?= $h->icon('dashboard', 'icon--sm') ?> <?= $h->t('Admin') ?></a><?php endif ?>
            <form method="post" action="<?= $h->url('auth.logout') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $h->icon('logout', 'icon--sm') ?> <?= $h->t('Sign out') ?></button></form>
        <?php else: ?>
            <a class="btn btn--primary btn--sm" href="<?= $h->url('auth.login') ?>"><?= $h->t('Sign in') ?></a>
        <?php endif ?>
    </div>
</header>
<?= $main ?>
<footer class="web-footer">
    <div class="web-footer__inner">
        <span>© <?= date('Y') ?> <?= $h->e($platform['name']) ?> · v<?= $h->e($platform['version']) ?></span>
        <span class="signature">Developer <a href="<?= $h->e($platform['authorUrl']) ?>" rel="noopener" target="_blank"><?= $h->e($platform['author']) ?></a></span>
    </div>
</footer>
<div class="toasts" role="status" aria-live="polite"></div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
