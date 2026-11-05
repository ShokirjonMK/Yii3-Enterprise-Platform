<?php

declare(strict_types=1);

/**
 * SSO provider create/edit form. The client secret is write-only: never rendered, only "set / not set".
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $code
 * @var array $values
 * @var array $errors
 * @var string $type
 * @var array<string,string> $types
 * @var list<string> $fields
 * @var list<string> $required
 * @var array<string,string> $roles
 * @var array<string,string> $tenants
 * @var bool $cross
 * @var bool $prod
 * @var string $csrf
 */

$editing = $code !== null;
$title = $editing ? $h->tr('Edit SSO provider') : $h->tr('New {type} provider', ['type' => $types[$type] ?? $type]);
$this->setTitle($title);
$this->setParameter('breadcrumbs', $editing
    ? [$h->tr('Authentication') => $h->url('admin.auth'), (string) ($values['name'] ?? $code) => $h->url('admin.auth.providers.view', ['code' => $code]), $h->tr('Edit')]
    : [$h->tr('Authentication') => $h->url('admin.auth'), $title]);
$cfg = $values['config'];
$f = fn(array $p) => $this->render($h->view('@admin/Users/views/_field'), $p + ['errors' => $errors]);
$fieldDefs = [
    'issuer' => [$h->tr('Issuer'), 'url', $type === 'oidc' ? $h->tr('e.g. https://login.example.uz/realms/corp — discovery is read from ISSUER/.well-known/openid-configuration') : $h->tr('Identifier stored with linked identities (e.g. https://idp.example.uz)')],
    'discovery_url' => [$h->tr('Discovery URL (optional)'), 'url', $h->tr('Override when the metadata document is not at the standard location.')],
    'client_id' => [$h->tr('Client ID'), 'text', null],
    'scopes' => [$h->tr('Scopes'), 'text', $h->tr('Space separated; must include openid.')],
    'scope' => [$h->tr('Scope'), 'text', $type === 'oneid' ? $h->tr('Issued by the OneID operator together with the client id.') : null],
    'username_claim' => [$h->tr('Username claim'), 'text', null],
    'token_auth' => [$h->tr('Token endpoint authentication'), 'select', null],
    'use_userinfo' => [$h->tr('Load claims from the userinfo endpoint'), 'checkbox', null],
    'link_by_verified_email' => [$h->tr('Link existing accounts by verified e-mail'), 'checkbox', null],
    'allow_insecure_http' => [$h->tr('Allow plain HTTP endpoints (development only)'), 'checkbox', null],
    'endpoint' => [$h->tr('OneID endpoint URL'), 'url', $h->tr('Exact URL from the OneID integration agreement (authorization and token calls use the same endpoint).')],
    'authorize_url' => [$h->tr('Authorization URL'), 'url', null],
    'token_url' => [$h->tr('Token URL'), 'url', null],
    'userinfo_url' => [$h->tr('User info URL'), 'url', null],
    'subject_claim' => [$h->tr('Subject claim'), 'text', $h->tr('Stable unique user id in the user-info response.')],
    'email_claim' => [$h->tr('E-mail claim'), 'text', null],
    'name_claim' => [$h->tr('Name claim'), 'text', null],
];
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1>
    <p class="page-head__sub"><?= $h->t('Saving requires a recent re-authentication (password + MFA).') ?></p></div></div>

<div class="card">
    <form class="card__body form form--2" method="post" novalidate autocomplete="off"
          action="<?= $editing ? $h->url('admin.auth.providers.update', ['code' => $code]) : $h->url('admin.auth.providers.store') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <input type="hidden" name="type" value="<?= $h->e($type) ?>">
        <h2 class="span-2"><?= $h->t('General') ?></h2>
        <?php if ($editing): ?>
            <div class="field"><span class="label"><?= $h->t('Code') ?></span><div class="mono"><?= $h->e($code) ?></div></div>
        <?php else: ?>
            <?= $f(['name' => 'code', 'label' => $h->tr('Code'), 'value' => $values['code'] ?? '', 'required' => true, 'hint' => $h->tr('Used in the callback URL /auth/sso/CODE/callback; cannot be changed later.')]) ?>
        <?php endif ?>
        <?= $f(['name' => 'name', 'label' => $h->tr('Display name (login button)'), 'value' => $values['name'] ?? '', 'required' => true]) ?>

        <h2 class="span-2"><?= $h->t('Connection') ?></h2>
        <?php foreach ($fields as $key):
            if ($key === 'allow_insecure_http' && $prod) { continue; }
            [$label, $ftype, $hint] = $fieldDefs[$key] ?? [$key, 'text', null];
            $p = ['name' => "config[$key]", 'errorKey' => "config.$key", 'label' => $label, 'value' => $cfg[$key] ?? '', 'hint' => $hint, 'required' => in_array($key, $required, true)];
            if ($ftype === 'select') {
                $p += ['type' => 'select', 'options' => ['client_secret_basic' => 'client_secret_basic', 'client_secret_post' => 'client_secret_post']];
            } elseif ($ftype === 'url') {
                $p += ['type' => 'url', 'attrs' => ['inputmode' => 'url', 'spellcheck' => 'false']];
            } else {
                $p += ['type' => $ftype];
            } ?>
            <?= $f($p) ?>
        <?php endforeach ?>

        <div class="field">
            <label for="f-secret"><?= $h->t('Client secret') ?></label>
            <input type="password" id="f-secret" name="secret" value="" autocomplete="new-password" aria-describedby="f-secret-hint">
            <div class="field__hint" id="f-secret-hint">
                <?= $h->t('Current state:') ?> <?= !empty($values['has_secret']) ? $h->badge('ok', $h->tr('set')) : $h->badge('unknown', $h->tr('not set')) ?>
                · <?= $h->t('Leave empty to keep the stored secret. Stored encrypted; never displayed.') ?>
            </div>
            <?php foreach ($errors['secret'] ?? [] as $err): ?><div class="field__error"><?= $h->t($err) ?></div><?php endforeach ?>
        </div>

        <h2 class="span-2"><?= $h->t('Accounts') ?></h2>
        <?= $f(['name' => 'auto_provision', 'label' => $h->tr('Create accounts automatically on first sign-in (SSO-only)'), 'type' => 'checkbox', 'value' => $values['auto_provision'] ?? false]) ?>
        <?= $f(['name' => 'default_role', 'label' => $h->tr('Role for auto-provisioned accounts'), 'type' => 'select', 'value' => $values['default_role'] ?? 'user', 'options' => $roles, 'hint' => $h->tr('Administrative roles can never be granted automatically.')]) ?>
        <?php if ($cross): ?>
            <?= $f(['name' => 'tenant_id', 'label' => $h->tr('Restrict to tenant'), 'type' => 'select', 'value' => $values['tenant_id'] ?? '', 'options' => ['' => $h->tr('— all tenants —')] + $tenants]) ?>
        <?php endif ?>
        <?= $f(['name' => 'sort_order', 'label' => $h->tr('Order on the login page'), 'type' => 'number', 'value' => $values['sort_order'] ?? 0, 'attrs' => ['min' => '0', 'max' => '999']]) ?>
        <?= $f(['name' => 'redirect_allowlist', 'label' => $h->tr('Redirect URI allowlist'), 'type' => 'textarea', 'value' => $values['redirect_allowlist'] ?? '', 'class' => 'span-2', 'hint' => $h->tr('Optional, one URI per line. When set, the callback URL must be listed.')]) ?>

        <div class="span-2 row">
            <button type="submit" class="btn btn--primary"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save changes') : $h->t('Create provider') ?></button>
            <a class="btn btn--ghost" data-back href="<?= $editing ? $h->url('admin.auth.providers.view', ['code' => $code]) : $h->url('admin.auth') ?>"><?= $h->t('Cancel') ?></a>
        </div>
    </form>
</div>
