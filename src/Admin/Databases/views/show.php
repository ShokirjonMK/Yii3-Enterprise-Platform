<?php

declare(strict_types=1);

use App\Platform\Database\Connections\DatabaseDriver;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Platform\Database\Connections\ConnectionDefinition $d
 * @var array{ok:bool, error:?string, version:?string, size:?int, objects:list<array{name:string,kind:string,rows:?int,size:?int}>, kind:string}|null $info
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($d->name);
$this->setParameter('breadcrumbs', [$h->tr('Databases') => $h->url('admin.databases'), $d->name]);
$manage = $can('databases.manage') && $d->editable();
$query = $can('databases.query') && $d->enabled;
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$code = match ($d->family()) {
    'document' => "\$dbs->mongo('{$d->name}')->selectCollection('…')->find([...]);",
    'olap' => "\$dbs->clickhouse('{$d->name}')->select('SELECT … WHERE day = {d:Date}', ['d' => \$day]);",
    default => "(new Query(\$dbs->sql('{$d->name}')))->from('…')->where([...])->all();",
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($d->name) ?> <span class="tag"><?= $h->e(DatabaseDriver::label($d->driver)) ?></span></h1>
        <p class="page-head__sub row"><?= $d->enabled ? $h->badge('enabled') : $h->badge('disabled') ?><?= $d->readOnly ? ' ' . $h->badge('info', $h->tr('read-only')) : '' ?>
            <span class="tag"><?= $h->e(['system' => $h->tr('platform database'), 'env' => $h->tr('defined in .env'), 'panel' => $h->tr('managed in the panel')][$d->source] ?? $d->source) ?></span></p>
    </div>
    <div class="page-head__actions">
        <button class="btn" hx-post="<?= $h->url('admin.databases.test', ['name' => $d->name]) ?>" hx-target="#test-result" hx-swap="outerHTML"><?= $h->icon('play') ?> <?= $h->t('Test connection') ?></button>
        <?php if ($query): ?><a class="btn" href="<?= $h->url('admin.databases.console', ['name' => $d->name]) ?>"><?= $h->icon('code') ?> <?= $h->t('Query console') ?></a><?php endif ?>
        <?php if ($manage): ?>
            <a class="btn" href="<?= $h->url('admin.databases.edit', ['name' => $d->name]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
            <form method="post" action="<?= $h->url('admin.databases.toggle', ['name' => $d->name]) ?>"<?= $d->enabled ? ' data-confirm="' . $h->t('Disable this connection? Code that uses it will get an error.') . '"' : '' ?>><?= $csrfField ?>
                <input type="hidden" name="enabled" value="<?= $d->enabled ? '0' : '1' ?>">
                <button class="btn<?= $d->enabled ? ' btn--danger' : ' btn--primary' ?>" type="submit"><?= $h->icon($d->enabled ? 'pause' : 'play') ?> <?= $d->enabled ? $h->t('Disable') : $h->t('Enable') ?></button></form>
            <form method="post" action="<?= $h->url('admin.databases.delete', ['name' => $d->name]) ?>" data-confirm="<?= $h->t('Delete this connection? Only the connection settings are removed, not the database.') ?>"><?= $csrfField ?>
                <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
        <?php endif ?>
    </div>
</div>
<div id="test-result"></div>

<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__head"><h2><?= $info === null ? $h->t('Objects') : ($info['kind'] === 'collections' ? $h->t('Collections') : $h->t('Tables')) ?></h2>
            <?php if ($info !== null && $info['ok']): ?><span class="small muted"><?= count($info['objects']) ?></span><?php endif ?></div>
        <?php if ($info === null): ?>
            <div class="card__body"><div class="empty"><?= $h->t('The connection is disabled.') ?></div></div>
        <?php elseif (!$info['ok']): ?>
            <div class="card__body"><div class="alert alert--error"><strong><?= $h->t('Connection failed') ?></strong><div class="small"><?= $h->e((string) $info['error']) ?></div></div></div>
        <?php elseif ($info['objects'] === []): ?>
            <div class="card__body"><div class="empty"><?= $h->t('No tables or collections yet.') ?></div></div>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th><?= $h->t('Name') ?></th><th><?= $h->t('Type') ?></th><th class="num"><?= $info['kind'] === 'collections' ? $h->t('Documents') : $h->t('Rows (est.)') ?></th><th class="num"><?= $h->t('Size') ?></th><?php if ($query): ?><th></th><?php endif ?></tr></thead>
                <tbody>
                <?php foreach ($info['objects'] as $o): ?>
                    <tr>
                        <td class="mono"><?= $h->e($o['name']) ?></td>
                        <td><span class="tag"><?= $h->e($o['kind']) ?></span></td>
                        <td class="num"><?= $o['rows'] === null ? '—' : number_format($o['rows'], 0, '.', ' ') ?></td>
                        <td class="num"><?= $o['size'] === null ? '—' : $h->bytes($o['size']) ?></td>
                        <?php if ($query): ?><td class="actions"><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.databases.console', ['name' => $d->name], ['object' => $o['name']]) ?>"><?= $h->icon('search', 'icon--sm') ?></a></td><?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Connection') ?></h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt><?= $h->t('Target') ?></dt><dd class="mono small"><?= $h->e($d->target()) ?></dd>
                    <?php if ($info !== null && $info['ok']): ?>
                        <dt><?= $h->t('Server version') ?></dt><dd><?= $h->e((string) $info['version']) ?></dd>
                        <dt><?= $h->t('Size') ?></dt><dd><?= $info['size'] === null ? '—' : $h->bytes($info['size']) ?></dd>
                    <?php endif ?>
                    <dt><?= $h->t('User') ?></dt><dd><?= $h->e($d->username !== '' ? $d->username : '—') ?></dd>
                    <dt><?= $h->t('Password') ?></dt><dd><?= $d->password !== '' ? $h->badge('ok', $h->tr('set (encrypted / from .env)')) : $h->t('none') ?></dd>
                    <dt><?= $h->t('Options') ?></dt><dd><?php if ($d->options === []): ?>—<?php else: foreach ($d->options as $k => $v): ?><span class="tag"><?= $h->e($k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : $v)) ?></span> <?php endforeach; endif ?></dd>
                    <?php if ($d->description !== ''): ?><dt><?= $h->t('Description') ?></dt><dd><?= $h->e($d->description) ?></dd><?php endif ?>
                    <?php if ($d->updatedAt !== null): ?><dt><?= $h->t('Updated') ?></dt><dd><?= $h->datetime($d->updatedAt) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Use in code') ?></h2></div>
            <div class="card__body">
                <p class="small muted"><?= $h->t('Inject App\Platform\Database\Connections\DatabaseManager $dbs:') ?></p>
                <pre class="code small"><?= $h->e($code) ?></pre>
            </div>
        </div>
    </div>
</div>
