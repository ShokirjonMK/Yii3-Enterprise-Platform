<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $id
 * @var array $values
 * @var array $errors
 * @var bool $cross
 * @var array<string,string> $tenants
 * @var array<string,string> $organizations
 * @var list<string> $policies
 * @var string $csrf
 */

$editing = $id !== null;
$title = $editing ? $h->tr('Edit user') : $h->tr('New user');
$this->setTitle($title);
$this->setParameter('breadcrumbs', $editing
    ? [$h->tr('Users') => $h->url('admin.users'), (string) ($values['username'] ?? '') => $h->url('admin.users.view', ['id' => $id]), $h->tr('Edit')]
    : [$h->tr('Users') => $h->url('admin.users'), $title]);
$v = static fn(string $k, $d = '') => $values[$k] ?? $d;
$f = fn(array $p) => $this->render('//admin/users/_field', $p + ['errors' => $errors]);
$action = $editing ? $h->url('admin.users.update', ['id' => $id]) : $h->url('admin.users.store');
$policyLabels = ['any' => $h->tr('Password or SSO'), 'local_only' => $h->tr('Local password only (break-glass)'), 'sso_only' => $h->tr('SSO only (no password)')];
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div></div>

<?php foreach ($errors['_form'] ?? [] as $err): ?>
    <div class="alert alert--error" role="alert"><?= $h->t($err) ?></div>
<?php endforeach ?>

<div class="card">
    <form class="card__body form form--2" method="post" action="<?= $h->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="version" value="<?= $h->e($v('version')) ?>">
            <div class="field"><span class="label"><?= $h->t('Username') ?></span><div class="mono"><?= $h->e($v('username')) ?></div></div>
        <?php else: ?>
            <?= $f(['name' => 'username', 'label' => $h->tr('Username'), 'value' => $v('username'), 'required' => true, 'hint' => $h->tr('3–64 characters: letters, digits, . _ @ -'), 'attrs' => ['autocomplete' => 'off']]) ?>
        <?php endif ?>
        <?= $f(['name' => 'full_name', 'label' => $h->tr('Full name'), 'value' => $v('full_name')]) ?>
        <?= $f(['name' => 'email', 'label' => $h->tr('E-mail'), 'type' => 'email', 'value' => $v('email')]) ?>
        <?= $f(['name' => 'phone', 'label' => $h->tr('Phone'), 'value' => $v('phone')]) ?>
        <?= $f(['name' => 'auth_policy', 'label' => $h->tr('Sign-in policy'), 'type' => 'select', 'value' => $v('auth_policy', 'any'), 'options' => array_intersect_key($policyLabels, array_flip($policies))]) ?>
        <?= $f(['name' => 'locale', 'label' => $h->tr('Language'), 'type' => 'select', 'value' => $v('locale', 'uz'), 'options' => ['uz' => "O'zbekcha", 'ru' => 'Русский', 'en' => 'English']]) ?>
        <?php if ($cross): ?>
            <?= $f(['name' => 'tenant_id', 'label' => $h->tr('Tenant'), 'type' => 'select', 'value' => $v('tenant_id'), 'options' => ['' => $h->tr('— none —')] + $tenants,
                'hint' => $editing ? $h->tr('The organization must belong to the selected tenant.') : $h->tr('Changing the tenant reloads the organization list.'),
                'attrs' => $editing ? [] : ['hx-get' => $h->url('admin.users.create'), 'hx-trigger' => 'change', 'hx-include' => 'closest form', 'hx-params' => 'not password,_csrf', 'hx-target' => '#main', 'hx-select' => '#main', 'hx-swap' => 'outerHTML']]) ?>
        <?php endif ?>
        <?= $f(['name' => 'organization_id', 'label' => $h->tr('Organization'), 'type' => 'select', 'value' => $v('organization_id'), 'options' => ['' => $h->tr('— none —')] + $organizations]) ?>
        <?php if (!$editing): ?>
            <?= $f(['name' => 'password', 'label' => $h->tr('Initial password'), 'type' => 'password', 'value' => '', 'hint' => $h->tr('Leave empty to generate a temporary password (shown once). The user must change it at first sign-in.'), 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <?php endif ?>
        <?= $f(['name' => 'mfa_required', 'label' => $h->tr('Require two-factor authentication'), 'type' => 'checkbox', 'value' => $v('mfa_required', false)]) ?>
        <div class="span-2 row">
            <button type="submit" class="btn btn--primary"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save changes') : $h->t('Create user') ?></button>
            <a class="btn btn--ghost" href="<?= $editing ? $h->url('admin.users.view', ['id' => $id]) : $h->url('admin.users') ?>"><?= $h->t('Cancel') ?></a>
        </div>
    </form>
</div>
