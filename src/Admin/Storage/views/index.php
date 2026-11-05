<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var string $health
 * @var array<string,mixed> $details
 * @var array{files:int, bytes:int, deleted:int} $totals
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{q:string,mime:string,entity_type:string,deleted:string} $filters
 * @var string $sort
 * @var int $maxBytes
 * @var list<string> $extensions
 * @var array<string, list<string>> $errors
 * @var bool $crossTenant
 */

$this->setTitle($h->tr('Storage & Search'));
$this->setParameter('breadcrumbs', [$h->tr('Storage & Search')]);
$manage = $can('storage.manage');
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
$url = $h->url('admin.storage');
$healthLabel = ['up' => 'Up', 'degraded' => 'Bucket missing', 'down' => 'Down', 'not_configured' => 'Not configured'][$health] ?? $health;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Storage & Search') ?></h1>
        <p class="page-head__sub"><?= $h->t('Private S3-compatible object storage. Downloads go through the application (authorised, audited) or short-lived pre-signed URLs.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.storage']) ?>

<?php if ($health === 'not_configured'): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <div><strong><?= $h->t('Not configured') ?></strong> — <?= $h->t('Set S3_ENDPOINT, S3_KEY, S3_SECRET and S3_BUCKET.') ?></div></div>
<?php elseif ($health === 'down'): ?>
    <div class="alert alert--error"><?= $h->icon('alert') ?> <div><strong><?= $h->t('Object storage unreachable') ?></strong> — <span class="mono small"><?= $h->e($details['error'] ?? '') ?></span></div></div>
<?php endif ?>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('box') ?> <?= $h->t('S3 bucket') ?></div><div class="kpi__value"><?= $h->badge($health === 'up' ? 'healthy' : $health, $h->tr($healthLabel)) ?></div><div class="kpi__meta mono"><?= $h->e(($details['bucket'] ?? '') . ' @ ' . ($details['endpoint'] ?? '—')) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('file') ?> <?= $h->t('Files') ?></div><div class="kpi__value"><?= number_format($totals['files'], 0, '.', ' ') ?></div><div class="kpi__meta"><?= $h->t('{n} deleted', ['n' => $totals['deleted']]) ?><?= $crossTenant ? '' : ' · ' . $h->t('own tenant') ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('database') ?> <?= $h->t('Stored volume') ?></div><div class="kpi__value"><?= $h->e($h->bytes($totals['bytes'])) ?></div><div class="kpi__meta">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('shield') ?> <?= $h->t('Upload limit') ?></div><div class="kpi__value"><?= $h->e($h->bytes($maxBytes)) ?></div><div class="kpi__meta"><?= $h->t('{n} allowed types', ['n' => count($extensions)]) ?></div></div>
</div>

<?php if ($manage): ?>
<form class="card mt" method="post" action="<?= $h->url('admin.storage.upload') ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__head"><h2><?= $h->t('Upload file') ?></h2></div>
    <div class="card__body form form--2">
        <div class="field span-2<?= isset($errors['file']) ? ' field--invalid' : '' ?>">
            <label for="file"><?= $h->t('File') ?></label>
            <input type="file" id="file" name="file" required accept="<?= $h->e(implode(',', array_map(static fn($e) => '.' . $e, $extensions))) ?>">
            <?php foreach ($errors['file'] ?? [] as $m): ?><div class="field__error"><?= $h->t($m) ?></div><?php endforeach ?>
            <div class="field__hint"><?= $h->t('Allowed: {list}. Content is verified server-side (MIME sniffing, script and executable detection); the original name is kept as metadata only.', ['list' => implode(', ', $extensions)]) ?></div>
        </div>
        <div class="field<?= isset($errors['entity_type']) ? ' field--invalid' : '' ?>">
            <label for="entity_type"><?= $h->t('Entity type (optional)') ?></label>
            <input type="text" id="entity_type" name="entity_type" pattern="[a-z0-9_.\-]{1,96}" placeholder="project">
            <?php foreach ($errors['entity_type'] ?? [] as $m): ?><div class="field__error"><?= $h->t($m) ?></div><?php endforeach ?>
        </div>
        <div class="field">
            <label for="entity_id"><?= $h->t('Entity id (optional)') ?></label>
            <input type="text" id="entity_id" name="entity_id" maxlength="96">
        </div>
    </div>
    <div class="card__foot"><button class="btn btn--primary" type="submit"<?= $health === 'up' ? '' : ' disabled' ?>><?= $h->icon('plus') ?> <?= $h->t('Upload') ?></button></div>
</form>
<?php endif ?>

<div id="presign-result" class="mt"></div>

<div class="card mt">
    <form class="toolbar" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Name, checksum, entity id…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="mime" aria-label="<?= $h->t('Type') ?>">
            <option value=""><?= $h->t('All types') ?></option>
            <?php foreach (['image/' => 'Images', 'application/pdf' => 'PDF', 'text/' => 'Text', 'application/vnd' => 'Office', 'application/zip' => 'ZIP'] as $k => $label): ?><option value="<?= $h->e($k) ?>"<?= $filters['mime'] === $k ? ' selected' : '' ?>><?= $h->t($label) ?></option><?php endforeach ?>
        </select>
        <input type="text" name="entity_type" value="<?= $h->e($filters['entity_type']) ?>" placeholder="<?= $h->t('Entity type') ?>" class="runtime-input-sm" aria-label="<?= $h->t('Entity type') ?>">
        <select name="deleted" aria-label="<?= $h->t('Deleted') ?>">
            <option value=""><?= $h->t('Active files') ?></option>
            <option value="only"<?= $filters['deleted'] === 'only' ? ' selected' : '' ?>><?= $h->t('Deleted only') ?></option>
            <option value="all"<?= $filters['deleted'] === 'all' ? ' selected' : '' ?>><?= $h->t('All') ?></option>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><div><?= $h->t('No stored files.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Name'), 'name', $page->sort, $query, 'admin.storage') ?></th>
            <th><?= $h->sortLink($h->tr('Type'), 'mime', $page->sort, $query, 'admin.storage') ?></th>
            <th class="num"><?= $h->sortLink($h->tr('Size'), 'size', $page->sort, $query, 'admin.storage') ?></th>
            <th><?= $h->t('Entity') ?></th><th><?= $h->t('SHA-256') ?></th>
            <th><?= $h->sortLink($h->tr('Uploaded'), 'created_at', $page->sort, $query, 'admin.storage') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $f): $deleted = $f['deleted_at'] !== null; ?>
            <tr>
                <td><?= $h->e($f['original_name']) ?><?= $deleted ? ' ' . $h->badge('failed', $h->tr('deleted')) : '' ?></td>
                <td class="small mono"><?= $h->e($f['mime_type']) ?></td>
                <td class="num small"><?= $h->e($h->bytes($f['size_bytes'])) ?></td>
                <td class="small"><?= $h->e(trim(($f['entity_type'] ?? '') . ' ' . ($f['entity_id'] ?? ''))) ?: '—' ?></td>
                <td class="mono small" title="<?= $h->e($f['checksum_sha256']) ?>"><?= $h->e(substr((string) $f['checksum_sha256'], 0, 12)) ?>…</td>
                <td class="small nowrap"><?= $h->ago($f['created_at']) ?></td>
                <td class="actions">
                    <?php if (!$deleted): ?>
                    <div class="btn-group">
                        <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.storage.download', ['id' => $f['id']]) ?>" download aria-label="<?= $h->t('Download') ?>"><?= $h->icon('download') ?></a>
                        <button type="button" class="btn btn--sm btn--ghost" hx-post="<?= $h->url('admin.storage.presign', ['id' => $f['id']]) ?>" hx-vals='{"ttl":"300"}' hx-target="#presign-result" hx-swap="innerHTML" aria-label="<?= $h->t('Pre-signed URL') ?>"><?= $h->icon('globe') ?></button>
                        <?php if ($manage): ?>
                        <form method="post" action="<?= $h->url('admin.storage.delete', ['id' => $f['id']]) ?>" data-confirm="<?= $h->t('Delete {name}? Requires re-authentication.', ['name' => $f['original_name']]) ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <button class="btn btn--sm btn--danger" type="submit" aria-label="<?= $h->t('Delete') ?>"><?= $h->icon('trash') ?></button>
                        </form>
                        <?php endif ?>
                    </div>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.storage', 'query' => $query]) ?>
</div>
