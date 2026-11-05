<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var string $prefix
 * @var string $cursor
 * @var array{keys:list<array<string,mixed>>, cursor:string, scanned_batches:int} $result
 * @var string|null $error
 * @var string $appPrefix
 * @var array<string,string> $namespaces
 */

$this->setTitle($h->tr('Keys & cache'));
$this->setParameter('breadcrumbs', [$h->tr('Redis Management') => $h->url('admin.redis'), $h->tr('Keys & cache')]);
$url = $h->url('admin.redis.keys');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Keys & cache') ?></h1>
        <p class="page-head__sub"><?= $h->t('Browse keys under {p} with SCAN (never KEYS). Values of sessions, locks, rate limits and secret-looking keys are never shown.', ['p' => $appPrefix]) ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.redis.keys']) ?>

<?php if ($can('redis.manage')): ?>
<form class="card mb" method="post" action="<?= $h->url('admin.redis.cache.clear') ?>" data-confirm="<?= $h->t('Delete the matching cache keys? Values are recomputed on demand. Requires re-authentication.') ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="toolbar">
        <strong><?= $h->t('Clear cache') ?></strong>
        <span class="mono small"><?= $h->e($appPrefix) ?>cache:</span>
        <input type="text" name="namespace" class="mono runtime-input-sm" placeholder="<?= $h->t('optional sub-prefix, e.g. settings') ?>" pattern="[A-Za-z0-9:_.,\-]{0,160}" aria-label="<?= $h->t('Cache sub-prefix') ?>">
        <span class="mono small">*</span>
        <button class="btn btn--sm btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Clear') ?></button>
        <span class="small muted"><?= $h->t('Only the cache namespace can be cleared; FLUSHALL/FLUSHDB are never used.') ?></span>
    </div>
</form>
<?php endif ?>

<div class="grid grid--sidebar">
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="keyup changed delay:500ms from:find input[type=search], submit">
        <span class="mono small"><?= $h->e($appPrefix) ?></span>
        <input type="search" name="prefix" value="<?= $h->e($prefix) ?>" class="mono" placeholder="cache:" pattern="[A-Za-z0-9:_.,\-]{0,160}" aria-label="<?= $h->t('Key prefix') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Scan') ?></button>
        <?php foreach (array_keys($namespaces) as $ns): ?><a class="tag" href="<?= $h->url('admin.redis.keys', [], ['prefix' => $ns]) ?>"><?= $h->e($ns) ?></a><?php endforeach ?>
    </form>
    <?php if ($error !== null): ?>
        <div class="alert alert--error"><?= $h->e($error) ?></div>
    <?php elseif ($result['keys'] === []): ?>
        <div class="empty"><?= $h->icon('search') ?><div><?= $h->t('No keys found with this prefix.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Key') ?></th><th><?= $h->t('Type') ?></th><th class="num"><?= $h->t('TTL') ?></th><th class="num"><?= $h->t('Size') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($result['keys'] as $k): ?>
            <tr>
                <td class="mono small runtime-key"><?= $h->e($k['key']) ?><?= $k['sensitive'] ? ' ' . $h->icon('lock', 'icon--sm') : '' ?></td>
                <td><span class="tag"><?= $h->e($k['type']) ?></span></td>
                <td class="num small"><?= $k['ttl'] === -1 ? '∞' : ($k['ttl'] < 0 ? '—' : (int) $k['ttl'] . ' s') ?></td>
                <td class="num small"><?= $k['size'] === null ? '—' : $h->e($h->bytes($k['size'])) ?></td>
                <td class="actions"><button type="button" class="btn btn--sm btn--ghost" hx-get="<?= $h->url('admin.redis.key', [], ['key' => $k['key']]) ?>" hx-target="#key-detail" hx-swap="innerHTML"><?= $h->icon('eye') ?></button></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="pager">
        <span><?= $h->t('{n} keys shown · {b} SCAN batches', ['n' => count($result['keys']), 'b' => $result['scanned_batches']]) ?></span>
        <?php if ($result['cursor'] !== '0'): ?>
            <a class="btn btn--sm" href="<?= $h->url('admin.redis.keys', [], ['prefix' => $prefix, 'cursor' => $result['cursor']]) ?>" hx-get="<?= $h->url('admin.redis.keys', [], ['prefix' => $prefix, 'cursor' => $result['cursor']]) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><?= $h->t('Next batch') ?> ›</a>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>
<div class="card">
    <div class="card__head"><h2><?= $h->t('Key details') ?></h2></div>
    <div id="key-detail" class="card__body small muted"><?= $h->t('Select a key to see its metadata.') ?></div>
</div>
</div>
