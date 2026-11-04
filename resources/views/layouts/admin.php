<?php

declare(strict_types=1);

use App\Admin\Menu\AdminMenu;
use App\Shared\Infrastructure\View\PlatformAsset;

/**
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
 */

$title = $this->getTitle() ?: 'Admin';
$breadcrumbs = $this->getParameter('breadcrumbs', []);

$renderFlashes = static function () use ($flashes, $h): string {
    $out = '';
    foreach ($flashes as $type => $messages) {
        foreach ((array) $messages as $m) {
            $out .= '<div data-flash="' . $h->e($type) . '" data-flash-message="' . $h->e(is_array($m) ? ($m['body'] ?? '') : $m) . '" class="alert alert--' . $h->e($type) . '">' . $h->e(is_array($m) ? ($m['body'] ?? '') : $m) . '</div>';
        }
    }
    return $out;
};

$renderMain = static function (bool $withTitle = false) use ($content, $title, $breadcrumbs, $h, $renderFlashes): string {
    $crumbs = '';
    if ($breadcrumbs !== []) {
        $crumbs .= '<ol class="breadcrumbs"><li><a href="' . $h->url('admin.dashboard') . '">' . $h->t('Admin') . '</a></li>';
        foreach ($breadcrumbs as $label => $url) {
            $crumbs .= is_int($label) ? '<li>' . $h->e($url) . '</li>' : '<li><a href="' . $h->e($url) . '">' . $h->e($label) . '</a></li>';
        }
        $crumbs .= '</ol>';
    }
    return '<main id="main" class="content" tabindex="-1">' . ($withTitle ? '<title>' . $h->e($title) . ' · Admin</title>' : '') . $crumbs . $renderFlashes() . $content . '</main>';
};

// HTMX partial navigation: only the main region is needed (htmx selects #main and updates <title>)
if ($isHtmx) {
    echo $renderMain(true);
    return;
}

$assetManager->register(PlatformAsset::class);
$this->addCssFiles($assetManager->getCssFiles());
$this->addJsFiles($assetManager->getJsFiles());

$user = $identity?->user;
$initials = $user ? mb_strtoupper(mb_substr($user->displayName(), 0, 1) . mb_substr(strstr($user->displayName(), ' ') ?: '', 1, 1)) : '?';

$this->beginPage();
?>
<!DOCTYPE html>
<html lang="<?= $h->e($h->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"historyCacheSize":0,"defaultSwapStyle":"outerHTML","scrollIntoViewOnBoost":false,"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true}'>
    <meta name="csrf" content="<?= $h->e($csrf) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="author" content="<?= $h->e($platform['author']) ?>">
    <meta name="generator" content="Yii3 Enterprise Platform by <?= $h->e($platform['author']) ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title><?= $h->e($title) ?> · Admin</title>
    <?php $this->head() ?>
</head>
<body hx-headers='{"X-CSRF-Token":"<?= $h->e($csrf) ?>"}'>
<?php $this->beginBody() ?>
<a class="skip-link" href="#main"><?= $h->t('Skip to content') ?></a>
<script type="application/json" id="yep-i18n"><?= json_encode([
    'confirmTitle' => $h->tr('Please confirm'), 'cancel' => $h->tr('Cancel'), 'ok' => $h->tr('Confirm'),
    'requestFailed' => $h->tr('Request failed'), 'networkError' => $h->tr('Network error — check your connection'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="htmx-indicator progress-bar" id="global-progress" aria-hidden="true"></div>
<div class="shell">
    <aside class="sidebar" aria-label="<?= $h->t('Administration') ?>">
        <div class="sidebar__brand"><span class="sidebar__logo">Y3</span><span><?= $h->e($platform['name']) ?></span></div>
        <nav hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-indicator="#global-progress">
            <?php foreach (AdminMenu::groups() as $group):
                $visible = array_filter($group['items'], static fn($i) => $can($i['permission']) && $h->urlOrNull($i['route']) !== null);
                if ($visible === []) { continue; } ?>
                <div class="sidebar__group"><?= $h->t($group['group']) ?></div>
                <ul class="nav">
                    <?php foreach ($visible as $item):
                        $url = $h->url($item['route']);
                        $active = $h->isRoute($item['route']); ?>
                        <li><a href="<?= $h->e($url) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $h->icon($item['icon']) ?><span><?= $h->t($item['label']) ?></span><span class="nav__num"><?= sprintf('%02d', $item['n']) ?></span></a></li>
                    <?php endforeach ?>
                </ul>
            <?php endforeach ?>
        </nav>
        <div class="sidebar__foot">v<?= $h->e($platform['version']) ?> · <a href="/" class="muted"><?= $h->t('Open web app') ?></a><br><span class="signature"><?= $h->t('Built by') ?> <a href="<?= $h->e($platform['authorUrl']) ?>" rel="noopener" target="_blank"><?= $h->e($platform['author']) ?></a></span></div>
    </aside>

    <div class="main-col">
        <header class="topbar">
            <button type="button" class="btn btn--ghost btn--icon topbar__menu-btn" data-nav-toggle aria-label="<?= $h->t('Menu') ?>"><?= $h->icon('menu') ?></button>
            <form class="topbar__search" action="<?= $h->url('admin.search') ?>" method="get" role="search" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
                <?= $h->icon('search') ?>
                <input type="search" name="q" placeholder="<?= $h->t('Search users, tenants, jobs, audit…') ?>" aria-label="<?= $h->t('Search') ?>">
            </form>
            <div class="topbar__spacer"></div>
            <details class="dropdown">
                <summary class="btn btn--ghost btn--sm" aria-label="<?= $h->t('Language') ?>"><?= $h->icon('globe') ?> <?= $h->e(strtoupper($h->locale())) ?></summary>
                <div class="dropdown__menu">
                    <?php foreach ($platform['locales'] as $code => $label): ?>
                        <form method="post" action="<?= $h->url('auth.locale') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="locale" value="<?= $h->e($code) ?>"><button type="submit"><?= $h->e($label) ?></button></form>
                    <?php endforeach ?>
                </div>
            </details>
            <button type="button" class="btn btn--ghost btn--icon" data-theme-toggle aria-label="<?= $h->t('Toggle dark mode') ?>"><?= $h->icon('moon') ?></button>
            <a class="btn btn--ghost btn--icon" href="<?= $h->url('web.notifications') ?>" aria-label="<?= $h->t('Notifications') ?>"><?= $h->icon('bell') ?></a>
            <details class="dropdown">
                <summary class="row" aria-label="<?= $h->t('Account') ?>"><span class="avatar"><?= $h->e($initials) ?></span></summary>
                <div class="dropdown__menu">
                    <div class="small muted dropdown__head"><?= $h->e($user?->displayName()) ?><br><span class="mono"><?= $h->e($user?->username) ?></span></div>
                    <hr class="divider">
                    <a href="<?= $h->url('web.profile') ?>"><?= $h->icon('user') ?> <?= $h->t('My profile') ?></a>
                    <a href="<?= $h->url('web.security') ?>"><?= $h->icon('shield') ?> <?= $h->t('Security & sessions') ?></a>
                    <form method="post" action="<?= $h->url('auth.logout') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button type="submit"><?= $h->icon('logout') ?> <?= $h->t('Sign out') ?></button></form>
                </div>
            </details>
        </header>
        <?= $renderMain() ?>
    </div>
</div>
<div class="toasts" role="status" aria-live="polite"></div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
