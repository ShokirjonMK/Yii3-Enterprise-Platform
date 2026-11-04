<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array|null> $methods
 * @var list<array> $providers
 * @var array<string,string> $types
 * @var list<array> $history
 * @var bool $canSwitch
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Authentication'));
$this->setParameter('breadcrumbs', [$h->tr('Authentication')]);
$canManage = $can('auth.manage');
$labels = ['auth.local_login' => $h->tr('Local login (username + password)'), 'auth.sso' => $h->tr('Single sign-on (OneID / OIDC / OAuth 2.0)')];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Authentication') ?></h1>
        <p class="page-head__sub"><?= $h->t('Login methods and single sign-on providers') ?></p>
    </div>
    <?php if ($canManage): ?>
        <div class="page-head__actions">
            <details class="dropdown">
                <summary class="btn btn--primary"><?= $h->icon('plus') ?> <?= $h->t('Add provider') ?></summary>
                <div class="dropdown__menu">
                    <?php foreach ($types as $t => $label): ?>
                        <a href="<?= $h->url('admin.auth.providers.create', [], ['type' => $t]) ?>"><?= $h->e($label) ?></a>
                    <?php endforeach ?>
                </div>
            </details>
        </div>
    <?php endif ?>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Login methods') ?></h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col"><?= $h->t('Method') ?></th><th scope="col"><?= $h->t('Flag') ?></th><th scope="col"><?= $h->t('State') ?></th><th scope="col"><?= $h->t('Changed') ?></th><th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($methods as $code => $flag): ?>
            <tr>
                <td><?= $h->e($labels[$code] ?? $code) ?></td>
                <td><code><?= $h->e($code) ?></code></td>
                <td><?= $flag === null ? $h->badge('unconfigured', $h->tr('Not configured')) : $h->badge($flag['effective'] ? 'enabled' : 'disabled') ?></td>
                <td class="small"><?= $flag === null ? '—' : $h->ago($flag['updated_at']) ?></td>
                <td class="actions">
                    <?php if ($flag !== null && $canManage && $canSwitch): ?>
                        <form method="post" action="<?= $h->url('admin.auth.method', ['method' => $code]) ?>"
                              data-confirm="<?= $flag['effective'] ? $h->t('Switch off this login method for all users? (break-glass local_only accounts keep password sign-in)') : $h->t('Switch on this login method?') ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <input type="hidden" name="enabled" value="<?= $flag['effective'] ? '0' : '1' ?>">
                            <button class="btn btn--sm<?= $flag['effective'] ? ' btn--danger' : '' ?>" type="submit"><?= $flag['effective'] ? $h->t('Disable') : $h->t('Enable') ?></button>
                        </form>
                    <?php elseif ($canManage): ?>
                        <span class="small muted"><?= $h->t('Requires settings.manage') ?></span>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('SSO providers') ?></h2><span class="small muted"><?= count($providers) ?></span></div>
    <?php if ($providers === []): ?>
        <div class="empty"><?= $h->icon('key') ?><p><?= $h->t('No SSO providers configured yet. Add OneID or a corporate OpenID Connect provider.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col"><?= $h->t('Name') ?></th><th scope="col"><?= $h->t('Code') ?></th><th scope="col"><?= $h->t('Type') ?></th><th scope="col"><?= $h->t('Enabled') ?></th><th scope="col"><?= $h->t('Health') ?></th><th scope="col"><?= $h->t('Secret') ?></th><th scope="col"><?= $h->t('Last check') ?></th></tr></thead>
            <tbody>
            <?php foreach ($providers as $p): ?>
                <tr>
                    <td><a href="<?= $h->url('admin.auth.providers.view', ['code' => $p['code']]) ?>"><?= $h->e($p['name']) ?></a></td>
                    <td class="mono small"><?= $h->e($p['code']) ?></td>
                    <td class="small"><?= $h->e($types[$p['type']] ?? $p['type']) ?></td>
                    <td><?= $h->badge($p['enabled'] ? 'enabled' : 'disabled') ?></td>
                    <td><?= $h->badge($p['status'] === 'unconfigured' ? 'unconfigured' : $p['status'], $p['status'] === 'unconfigured' ? $h->tr('Not configured') : null) ?></td>
                    <td><?= $p['has_secret'] ? $h->badge('ok', $h->tr('set')) : $h->badge('unknown', $h->tr('not set')) ?></td>
                    <td class="small"><?= $h->ago($p['last_health_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Recent changes') ?></h2></div>
    <?php if ($history === []): ?>
        <div class="empty"><?= $h->t('No authentication configuration changes yet.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th scope="col"><?= $h->t('Time') ?></th><th scope="col"><?= $h->t('Action') ?></th><th scope="col"><?= $h->t('Target') ?></th><th scope="col"><?= $h->t('Actor') ?></th><th scope="col"><?= $h->t('Result') ?></th></tr></thead>
            <tbody>
            <?php foreach ($history as $a): ?>
                <tr>
                    <td class="small nowrap"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?></td>
                    <td><code><?= $h->e($a['action']) ?></code></td>
                    <td class="small mono"><?= $h->e($a['entity_id'] ?? '—') ?></td>
                    <td class="small"><?= $h->e($a['actor_name'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></td>
                    <td><?= $h->badge($a['result']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>
