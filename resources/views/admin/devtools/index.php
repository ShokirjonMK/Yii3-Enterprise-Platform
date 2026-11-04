<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $tab
 * @var array $data
 * @var string $filter
 * @var array<string,string> $tabs
 */
$this->setTitle($h->tr('Developer Tools'));
$this->setParameter('breadcrumbs', [$h->tr('Developer Tools')]);
$gii = $h->urlOrNull('admin.devtools.gii');
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Developer Tools') ?></h1>
        <p class="page-head__sub"><?= $h->t('Non-production only. Read-only views of routes, jobs, consumers, commands and the runtime.') ?></p></div>
    <div class="page-head__actions">
        <a class="btn" href="/api/docs"><?= $h->icon('api') ?> <?= $h->t('API docs') ?></a>
        <?php if ($gii !== null): ?><a class="btn btn--primary" href="<?= $h->e($gii) ?>"><?= $h->icon('code') ?> <?= $h->t('Enterprise Gii') ?></a>
        <?php else: ?><span class="btn" aria-disabled="true" title="<?= $h->t('Not installed yet') ?>"><?= $h->icon('code') ?> <?= $h->t('Enterprise Gii') ?></span><?php endif ?>
    </div>
</div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($tabs as $key => $label): ?><a href="<?= $h->url('admin.devtools', [], ['tab' => $key]) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a><?php endforeach ?>
</nav>
<div class="card" data-tab="<?= $h->e($tab) ?>">
<?php if ($tab === 'routes'): ?>
    <form class="toolbar" method="get" action="<?= $h->url('admin.devtools') ?>" hx-get="<?= $h->url('admin.devtools') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <input type="hidden" name="tab" value="routes"><input type="search" name="q" value="<?= $h->e($filter) ?>" placeholder="<?= $h->t('Filter by name or pattern…') ?>" aria-label="<?= $h->t('Filter') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button><span class="small muted"><?= $h->t('{n} routes', ['n' => count($data)]) ?></span>
    </form>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Methods') ?></th><th><?= $h->t('Pattern') ?></th><th><?= $h->t('Name') ?></th><th><?= $h->t('Permissions') ?></th><th><?= $h->t('Action') ?></th></tr></thead>
        <tbody><?php foreach ($data as $r): ?>
            <tr><td class="small"><span class="tag"><?= $h->e($r['methods']) ?></span></td><td class="mono small"><?= $h->e($r['pattern']) ?></td><td class="mono small"><?= $h->e($r['name']) ?></td>
                <td class="small"><?php foreach ($r['permissions'] as $p): ?><span class="tag"><?= $h->e($p) ?></span><?php endforeach ?><?= $r['reauth'] ? $h->badge('warning', $h->tr('re-auth')) : '' ?></td>
                <td class="mono small"><?= $h->e(str_replace('App\\', '', $r['action'])) ?></td></tr>
        <?php endforeach ?></tbody>
    </table></div>
<?php elseif ($tab === 'jobs'): ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Job type') ?></th><th><?= $h->t('Default queue') ?></th><th><?= $h->t('Handler') ?></th></tr></thead>
        <tbody><?php foreach ($data as $j): ?><tr><td class="mono"><?= $h->e($j['type']) ?></td><td><span class="tag"><?= $h->e($j['queue']) ?></span></td><td class="mono small"><?= $h->e($j['handler']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
<?php elseif ($tab === 'consumers'): ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Group') ?></th><th><?= $h->t('Topics') ?></th><th><?= $h->t('Handlers') ?></th></tr></thead>
        <tbody><?php foreach ($data as $group => $g): ?>
            <tr><td class="mono"><?= $h->e($group) ?></td><td><?php foreach ($g['topics'] ?? [] as $t): ?><span class="tag"><?= $h->e($t) ?></span><?php endforeach ?></td>
                <td class="small"><?php foreach ($g['handlers'] ?? [] as $ev => $hd): ?><div><span class="mono"><?= $h->e($ev) ?></span> → <span class="mono"><?= $h->e(is_string($hd) ? $hd : json_encode($hd)) ?></span></div><?php endforeach ?></td></tr>
        <?php endforeach ?></tbody>
    </table></div>
<?php elseif ($tab === 'commands'): ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Command') ?></th><th><?= $h->t('Class') ?></th></tr></thead>
        <tbody><?php foreach ($data as $name => $class): ?><tr><td class="mono">./yii <?= $h->e($name) ?></td><td class="mono small"><?= $h->e($class) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
<?php elseif ($tab === 'environment'): ?>
    <div class="card__body grid grid--3">
        <?php foreach ($data as $section => $rows): ?>
            <div><h3><?= $h->t($section) ?></h3><dl class="dl"><?php foreach ($rows as $k => $v): ?><dt><?= $h->e($k) ?></dt><dd class="mono small" data-env="<?= $h->e($k) ?>"><?= $h->e($v) ?></dd><?php endforeach ?></dl></div>
        <?php endforeach ?>
    </div>
    <div class="card__foot"><span class="small muted"><?= $h->t('Allowlisted values only; credentials and environment variables are never shown.') ?></span></div>
<?php elseif ($tab === 'extensions'): ?>
    <div class="card__body"><?php foreach ($data as $ext => $ver): ?><span class="tag"><?= $h->e($ext) ?><?= $ver !== '' ? ' ' . $h->e($ver) : '' ?></span><?php endforeach ?></div>
<?php elseif ($tab === 'migrations'): ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Migration') ?></th><th><?= $h->t('Applied') ?></th></tr></thead>
        <tbody><?php foreach ($data as $m): ?><tr><td class="mono"><?= $h->e($m['name']) ?></td><td class="small"><?= $h->datetime('@' . (int) $m['apply_time'], 'Y-m-d H:i:s') ?></td></tr><?php endforeach ?></tbody>
    </table></div>
<?php endif ?>
</div>
