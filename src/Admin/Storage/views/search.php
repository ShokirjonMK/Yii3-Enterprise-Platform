<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array{name:string, installed:?string, available:?string}> $extensions
 * @var list<array<string,mixed>> $indexes
 * @var string $term
 * @var array<string,mixed>|null $diag
 * @var string|null $error
 */

$this->setTitle($h->tr('Search diagnostics'));
$this->setParameter('breadcrumbs', [$h->tr('Storage & Search') => $h->url('admin.storage'), $h->tr('Search diagnostics')]);
$url = $h->url('admin.storage.search');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Platform search') ?></h1>
        <p class="page-head__sub"><?= $h->t('Search runs in PostgreSQL: case-insensitive "contains" (pg_trgm GIN indexes) and full-text (tsvector). No external search engine is required.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.storage.search']) ?>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Extensions') ?></h2></div>
        <table class="table table--compact"><thead><tr><th><?= $h->t('Extension') ?></th><th><?= $h->t('Installed') ?></th><th><?= $h->t('Available') ?></th></tr></thead><tbody>
            <?php foreach ($extensions as $e): ?>
                <tr><td class="mono small"><?= $h->e($e['name']) ?></td><td><?= $e['installed'] !== null ? $h->badge('enabled', $e['installed']) : $h->badge('not_configured', $h->tr('no')) ?></td><td class="small"><?= $h->e($e['available'] ?? '—') ?></td></tr>
            <?php endforeach ?>
        </tbody></table>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Diagnose a search term') ?></h2></div>
        <form class="card__body" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
            <div class="row">
                <input type="search" name="q" value="<?= $h->e($term) ?>" maxlength="100" placeholder="<?= $h->t('e.g. admin') ?>" aria-label="<?= $h->t('Search term') ?>" class="runtime-grow">
                <button class="btn btn--primary" type="submit"><?= $h->icon('search') ?> <?= $h->t('Analyse') ?></button>
            </div>
        </form>
        <?php if ($error !== null): ?><div class="alert alert--error"><?= $h->e($error) ?></div><?php endif ?>
        <?php if ($diag !== null): ?>
        <div class="card__body"><dl class="dl runtime-dl">
            <dt><?= $h->t('Trigrams') ?></dt><dd class="mono small"><?= $diag['trigram_available'] ? $h->e($diag['trigrams']) : $h->badge('not_configured', $h->tr('pg_trgm not installed')) ?></dd>
            <dt>tsvector</dt><dd class="mono small"><?= $h->e($diag['tsvector']) ?></dd>
            <dt>tsquery</dt><dd class="mono small"><?= $h->e($diag['tsquery']) ?></dd>
            <dt><?= $h->t('Users search plan') ?></dt><dd><?= $diag['users_uses_index'] ? $h->badge('ok', $h->tr('index used')) : $h->badge('warning', $h->tr('sequential scan')) ?>
                <div class="small mono"><?= $h->e(implode(' → ', $diag['users_plan'])) ?></div>
                <div class="small muted"><?= $h->t('Small tables are scanned sequentially by design; the planner switches to the trigram index as data grows.') ?></div></dd>
            <dt><?= $h->t('Matches / time') ?></dt><dd><?= (int) $diag['users_matches'] ?> · <?= $h->e($diag['users_ms']) ?> ms</dd>
            <?php if (!empty($diag['similar_usernames'])): ?>
            <dt><?= $h->t('Most similar usernames') ?></dt><dd><?php foreach ($diag['similar_usernames'] as $s): ?><span class="tag"><?= $h->e($s['username']) ?> <?= $h->e($s['score']) ?></span><?php endforeach ?></dd>
            <?php endif ?>
        </dl></div>
        <?php endif ?>
    </div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Search indexes') ?></h2><span class="small muted"><?= $h->t('trigram (gin/gist_trgm_ops) and full-text') ?></span></div>
    <?php if ($indexes === []): ?>
        <div class="empty"><?= $h->t('No trigram or full-text indexes found.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Index') ?></th><th><?= $h->t('Table') ?></th><th><?= $h->t('Kind') ?></th><th class="num"><?= $h->t('Size') ?></th><th><?= $h->t('Definition') ?></th></tr></thead>
        <tbody>
        <?php foreach ($indexes as $i): ?>
            <tr><td class="mono small"><?= $h->e($i['indexname']) ?></td><td class="mono small"><?= $h->e($i['tablename']) ?></td><td><span class="tag"><?= $h->e($i['kind']) ?></span></td><td class="num small"><?= $h->e($h->bytes($i['size_bytes'])) ?></td><td><code class="runtime-sql"><?= $h->e($i['indexdef']) ?></code></td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>
