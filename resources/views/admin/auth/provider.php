<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $provider   (secret removed; has_secret flag only)
 * @var array<string,string> $types
 * @var list<string> $required
 * @var string|null $callback
 * @var int $linkedCount
 * @var int $failures
 * @var list<array> $history
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($provider['name']);
$this->setParameter('breadcrumbs', [$h->tr('Authentication') => $h->url('admin.auth'), (string) $provider['name']]);
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$canManage = $can('auth.manage');
$missing = array_values(array_filter($required, static fn($k) => empty($provider['config'][$k])));
$code = (string) $provider['code'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($provider['name']) ?> <?= $h->badge($provider['enabled'] ? 'enabled' : 'disabled') ?></h1>
        <p class="page-head__sub"><span class="mono"><?= $h->e($code) ?></span> · <?= $h->e($types[$provider['type']] ?? $provider['type']) ?></p>
    </div>
    <?php if ($canManage): ?>
        <div class="page-head__actions">
            <form method="post" action="<?= $h->url('admin.auth.providers.test', ['code' => $code]) ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('activity') ?> <?= $h->t('Test connection') ?></button></form>
            <a class="btn" href="<?= $h->url('admin.auth.providers.edit', ['code' => $code]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
            <?php if ($provider['enabled']): ?>
                <form method="post" action="<?= $h->url('admin.auth.providers.disable', ['code' => $code]) ?>" data-confirm="<?= $h->t('Disable this provider? Users will no longer be able to sign in with it.') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('pause') ?> <?= $h->t('Disable') ?></button></form>
            <?php else: ?>
                <form method="post" action="<?= $h->url('admin.auth.providers.enable', ['code' => $code]) ?>" data-confirm="<?= $h->t('Enable this provider on the login page?') ?>"><?= $csrfInput ?><button class="btn btn--primary" type="submit"<?= $missing !== [] ? ' disabled' : '' ?>><?= $h->icon('play') ?> <?= $h->t('Enable') ?></button></form>
            <?php endif ?>
            <form method="post" action="<?= $h->url('admin.auth.providers.delete', ['code' => $code]) ?>" data-confirm="<?= $h->t('Delete this provider permanently?') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
        </div>
    <?php endif ?>
</div>

<?php if ($missing !== []): ?>
    <div class="alert alert--warning" role="status"><?= $h->icon('alert') ?> <span><?= $h->t('Not configured — required settings missing: {list}', ['list' => implode(', ', $missing)]) ?></span></div>
<?php endif ?>
<?php if ($provider['type'] === 'oneid'): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <span><?= $h->t('OneID: UNVERIFIED until real operator-issued credentials (endpoint, client id, secret, scope) are configured and a live sign-in succeeds. See docs/SSO.md.') ?></span></div>
<?php endif ?>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Configuration') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <?php foreach ($provider['config'] as $k => $val): ?>
                    <dt><code><?= $h->e($k) ?></code></dt>
                    <dd class="mono small"><?= is_bool($val) ? $h->badge($val ? 'enabled' : 'disabled', $val ? $h->tr('yes') : $h->tr('no')) : $h->e($val === '' ? '—' : $val) ?></dd>
                <?php endforeach ?>
                <dt><?= $h->t('Client secret') ?></dt>
                <dd><?= $provider['has_secret'] ? $h->badge('ok', $h->tr('set')) : $h->badge('unknown', $h->tr('not set')) ?></dd>
                <dt><?= $h->t('Callback URL') ?></dt>
                <dd class="mono small"><?= $callback !== null ? $h->e($callback) . ' <button type="button" class="btn btn--sm btn--ghost" data-copy="' . $h->e($callback) . '">' . $h->t('Copy') . '</button>' : $h->badge('failed', $h->tr('not in redirect allowlist')) ?></dd>
                <dt><?= $h->t('Redirect allowlist') ?></dt>
                <dd class="mono small"><?= $provider['redirect_allowlist'] === [] ? $h->t('any (callback URL is fixed)') : $h->e(implode(', ', $provider['redirect_allowlist'])) ?></dd>
                <dt><?= $h->t('Auto-provision') ?></dt>
                <dd><?= $provider['auto_provision'] ? $h->t('yes, default role {r}', ['r' => $provider['default_role']]) : $h->t('no — only pre-linked accounts') ?></dd>
                <dt><?= $h->t('Linked accounts') ?></dt>
                <dd><?= (int) $linkedCount ?></dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Health') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Status') ?></dt><dd><?= $h->badge($provider['status'], $provider['status'] === 'unconfigured' ? $h->tr('Not configured') : null) ?></dd>
                <dt><?= $h->t('Last check') ?></dt><dd><?= $h->datetime($provider['last_health_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Sign-in failures (24 h)') ?></dt><dd><?= (int) $failures ?></dd>
            </dl>
            <?php if (!empty($provider['last_health_error'])): ?>
                <p class="label mt"><?= $h->t('Last error') ?></p>
                <pre class="id-pre small"><?= $h->e($provider['last_health_error']) ?></pre>
            <?php endif ?>
        </div>
    </div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Audit history') ?></h2></div>
    <?php if ($history === []): ?>
        <div class="empty"><?= $h->t('No audit records for this provider.') ?></div>
    <?php else: ?>
        <ol class="timeline card__body">
            <?php foreach ($history as $a): ?>
                <li>
                    <div><code><?= $h->e($a['action']) ?></code> <?= $h->badge($a['result']) ?></div>
                    <div class="small muted"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($a['actor_name'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></div>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>
</div>
