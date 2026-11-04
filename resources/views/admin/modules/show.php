<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $m
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($m['name']);
$this->setParameter('breadcrumbs', [$h->tr('Business Modules') => $h->url('admin.modules'), $m['name']]);
$on = $m['flag_enabled'] && $m['enabled'];
$adminUrl = $m['admin_route'] ? $h->urlOrNull((string) $m['admin_route']) : null;
$manifest = $m['manifest'] ?? [];
unset($manifest['_dir']);
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($m['name']) ?> <span class="tag"><?= $h->e($m['code']) ?></span></h1>
        <p class="page-head__sub row"><span data-module-state="<?= $on ? 'on' : 'off' ?>"><?= $on ? $h->badge('enabled') : $h->badge('disabled') ?></span> <span class="small muted"><?= $h->t('flag {f}: {s}', ['f' => $m['flag'], 's' => $m['flag_status'] ?? 'not registered']) ?></span></p></div>
    <div class="page-head__actions">
        <?php if ($adminUrl !== null): ?><a class="btn" href="<?= $h->e($adminUrl) ?>"><?= $h->icon('grid') ?> <?= $h->t('Open module') ?></a><?php endif ?>
        <?php if ($can('modules.manage') && !$m['missing']): ?>
        <form method="post" action="<?= $h->url('admin.modules.toggle', ['code' => $m['code']]) ?>"<?= $on ? ' data-confirm="' . $h->t('Disable module {n}? Its routes and jobs stop working for all users.', ['n' => $m['name']]) . '"' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
            <button class="btn<?= $on ? ' btn--danger' : ' btn--primary' ?>" type="submit"><?= $h->icon($on ? 'pause' : 'play') ?> <?= $on ? $h->t('Disable') : $h->t('Enable') ?></button></form>
        <?php endif ?>
    </div>
</div>
<div class="grid grid--2">
    <div class="card"><div class="card__body"><dl class="dl">
        <dt><?= $h->t('Version') ?></dt><dd><?= $h->e($m['version']) ?><?= $m['installed_version'] && $m['installed_version'] !== $m['version'] ? ' ' . $h->badge('warning', $h->tr('registered {v}', ['v' => $m['installed_version']])) : '' ?></dd>
        <dt><?= $h->t('Registered') ?></dt><dd><?= $m['installed'] ? $h->datetime($m['installed_at']) : $h->t('no (use Sync registry or enable)') ?></dd>
        <dt><?= $h->t('Description') ?></dt><dd><?= $h->e($m['description']) ?></dd>
        <dt><?= $h->t('Routes') ?></dt><dd><?= (int) $m['routes'] ?></dd>
        <dt><?= $h->t('Permissions') ?></dt><dd><?php foreach ($m['permissions'] as $p): ?><span class="tag"><?= $h->e($p) ?></span><?php endforeach ?></dd>
        <?php foreach (['jobs' => 'Job types', 'topics' => 'Kafka topics', 'consumer_groups' => 'Consumer groups'] as $k => $label): if (!empty($manifest[$k])): ?>
            <dt><?= $h->t($label) ?></dt><dd><?php foreach ((array) $manifest[$k] as $x): ?><span class="tag"><?= $h->e($x) ?></span><?php endforeach ?></dd>
        <?php endif; endforeach ?>
    </dl></div></div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Manifest') ?></h2></div><div class="card__body"><pre><?= $h->json($manifest) ?></pre></div></div>
</div>
