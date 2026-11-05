<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Platform\Identity\Domain\User $user
 * @var array|null $tenant
 * @var array|null $organization
 * @var list<string> $roles         effective roles (incl. inherited)
 * @var list<string> $directRoles
 * @var array<string,array{description:string}> $catalogRoles
 * @var list<string> $adminRoles
 * @var bool $actorIsSuper
 * @var bool $isSelf
 * @var list<array> $sessions
 * @var string $currentSession
 * @var list<array> $identities
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($user->displayName());
$this->setParameter('breadcrumbs', [$h->tr('Users') => $h->url('admin.users'), $user->username]);
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$locked = $user->isLocked();
$isPrivileged = array_intersect($roles, $adminRoles) !== [];
$mayAdminister = $actorIsSuper || !$isPrivileged;
$canManage = $can('users.manage') && $mayAdminister;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($user->displayName()) ?> <?= $h->badge($user->status) ?><?= $locked ? ' ' . $h->badge('blocked', $h->tr('locked')) : '' ?></h1>
        <p class="page-head__sub mono"><?= $h->e($user->username) ?></p>
    </div>
    <?php if ($canManage && !$isSelf): ?>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.users.edit', ['id' => $user->id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
        <?php if ($user->status === 'active'): ?>
            <form method="post" action="<?= $h->url('admin.users.block', ['id' => $user->id]) ?>" data-confirm="<?= $h->t('Block this user? All sessions and API tokens will be revoked.') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('lock') ?> <?= $h->t('Block') ?></button></form>
        <?php else: ?>
            <form method="post" action="<?= $h->url('admin.users.unblock', ['id' => $user->id]) ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('check') ?> <?= $h->t('Unblock') ?></button></form>
        <?php endif ?>
        <?php if ($locked || $user->failedLogins > 0): ?>
            <form method="post" action="<?= $h->url('admin.users.unlock', ['id' => $user->id]) ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('key') ?> <?= $h->t('Unlock') ?></button></form>
        <?php endif ?>
        <?php if ($user->authPolicy !== 'sso_only'): ?>
            <form method="post" action="<?= $h->url('admin.users.reset-password', ['id' => $user->id]) ?>" data-confirm="<?= $h->t('Generate a new temporary password? The current password stops working and all sessions are terminated.') ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Reset password') ?></button></form>
        <?php endif ?>
        <form method="post" action="<?= $h->url('admin.users.archive', ['id' => $user->id]) ?>" data-confirm="<?= $h->t('Archive this user? The account is deactivated permanently; audit data is kept.') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('archive') ?> <?= $h->t('Archive') ?></button></form>
    </div>
    <?php endif ?>
</div>

<?php if ($isPrivileged && !$actorIsSuper): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <span><?= $h->t('This is an administrative account. Only a superadmin can modify it.') ?></span></div>
<?php endif ?>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Profile') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('E-mail') ?></dt><dd><?= $h->e($user->email ?? '—') ?></dd>
                <dt><?= $h->t('Phone') ?></dt><dd><?= $h->e($user->phone ?? '—') ?></dd>
                <dt><?= $h->t('Tenant') ?></dt><dd><?php if ($tenant !== null): ?><?php if ($can('tenants.view')): ?><a href="<?= $h->url('admin.tenants.view', ['id' => $tenant['id']]) ?>"><?= $h->e($tenant['name']) ?></a><?php else: ?><?= $h->e($tenant['name']) ?><?php endif ?><?php else: ?>—<?php endif ?></dd>
                <dt><?= $h->t('Organization') ?></dt><dd><?= $h->e($organization['name'] ?? '—') ?></dd>
                <dt><?= $h->t('Sign-in policy') ?></dt><dd><code><?= $h->e($user->authPolicy) ?></code></dd>
                <dt><?= $h->t('Two-factor') ?></dt><dd><?= $user->mfaEnabled ? $h->badge('enabled') : $h->badge($mfaPolicy->required ? 'pending' : 'disabled', $mfaPolicy->required ? $h->tr('required, not enrolled') : null) ?>
                    <?php foreach ($mfaMethods as $m): ?> <span class="tag"><?= $h->t(\App\Platform\Identity\Mfa\MfaMethodType::label($m['type'])) ?></span><?php endforeach ?>
                    <?php if ($user->mfaEnabled && $can('users.manage') && !$isSelf): ?>
                        <form method="post" action="<?= $h->url('admin.users.mfa-reset', ['id' => $user->id]) ?>" class="inline" data-confirm="<?= $h->t('Remove all two-factor methods of this user? Use it when a phone or key is lost.') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Reset 2FA') ?></button></form>
                    <?php endif ?>
                    <br><small class="muted"><?= $h->t('Policy') ?>: <?= $h->e(implode(', ', $mfaPolicy->sources)) ?> · <?= $mfaPolicy->required ? $h->t('required') : $h->t('optional') ?></small></dd>
                <dt><?= $h->t('Password changed') ?></dt><dd><?= $h->datetime($user->passwordChangedAt) ?><?= $user->mustChangePassword ? ' ' . $h->badge('pending', $h->tr('must change')) : '' ?></dd>
                <dt><?= $h->t('Failed sign-ins') ?></dt><dd><?= (int) $user->failedLogins ?><?= $locked ? ' · ' . $h->t('locked until {t}', ['t' => $user->lockedUntil?->format('Y-m-d H:i')]) : '' ?></dd>
                <dt><?= $h->t('Last sign-in') ?></dt><dd><?= $h->datetime($user->lastLoginAt) ?></dd>
                <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($user->createdAt) ?></dd>
                <dt><?= $h->t('Language') ?></dt><dd><?= $h->e($user->locale) ?></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Roles') ?></h2></div>
        <div class="card__body">
            <p class="small muted"><?= $h->t('Effective roles (including inherited):') ?>
                <?php foreach ($roles as $r): ?><span class="tag"><?= $h->e($r) ?></span><?php endforeach ?>
            </p>
            <?php if ($can('rbac.manage') && !$isSelf): ?>
                <form method="post" action="<?= $h->url('admin.users.roles', ['id' => $user->id]) ?>" class="form" data-confirm="<?= $h->t('Apply the role changes? This requires a recent re-authentication.') ?>">
                    <?= $csrfInput ?>
                    <fieldset class="id-roles">
                        <legend class="label"><?= $h->t('Direct role assignments') ?></legend>
                        <?php foreach ($catalogRoles as $name => $def):
                            $isAdminRole = in_array($name, $adminRoles, true);
                            $disabled = $isAdminRole && !$actorIsSuper; ?>
                            <label class="check">
                                <input type="checkbox" name="roles[]" value="<?= $h->e($name) ?>"<?= in_array($name, $directRoles, true) ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                                <span><code><?= $h->e($name) ?></code> <span class="small muted"><?= $h->e($def['description']) ?></span><?= $isAdminRole ? ' ' . $h->badge('warning', $h->tr('superadmin only')) : '' ?></span>
                            </label>
                            <?php if ($disabled && in_array($name, $directRoles, true)): ?><input type="hidden" name="roles[]" value="<?= $h->e($name) ?>"><?php endif ?>
                        <?php endforeach ?>
                    </fieldset>
                    <div><button class="btn btn--primary" type="submit"><?= $h->icon('shield') ?> <?= $h->t('Save roles') ?></button></div>
                </form>
            <?php elseif ($isSelf): ?>
                <p class="small muted"><?= $h->t('You cannot change your own roles.') ?></p>
            <?php endif ?>
        </div>
    </div>
</div>

<div class="card mt">
    <div class="card__head">
        <h2><?= $h->t('Active sessions') ?></h2>
        <span class="small muted"><?= count($sessions) ?></span>
        <?php if ($sessions !== [] && $can('sessions.revoke') && $mayAdminister): ?>
            <form method="post" action="<?= $h->url('admin.users.sessions.revoke-all', ['id' => $user->id]) ?>" data-confirm="<?= $h->t('Terminate all sessions of this user?') ?>"><?= $csrfInput ?><button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Terminate all') ?></button></form>
        <?php endif ?>
    </div>
    <?php if ($sessions === []): ?>
        <div class="empty"><?= $h->t('No active sessions.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th scope="col"><?= $h->t('Method') ?></th><th scope="col"><?= $h->t('IP') ?></th><th scope="col"><?= $h->t('Device') ?></th><th scope="col"><?= $h->t('Started') ?></th><th scope="col"><?= $h->t('Last seen') ?></th><th scope="col"><?= $h->t('MFA') ?></th><th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><code><?= $h->e($s['auth_method']) ?></code><?= $s['id'] === $currentSession ? ' ' . $h->badge('active', $h->tr('this session')) : '' ?></td>
                    <td class="mono small"><?= $h->e($s['ip'] ?? '—') ?></td>
                    <td class="small"><?= $h->e(mb_strimwidth((string) ($s['user_agent'] ?? ''), 0, 70, '…')) ?></td>
                    <td class="small nowrap"><?= $h->datetime($s['created_at']) ?></td>
                    <td class="small nowrap"><?= $h->ago($s['last_seen_at']) ?></td>
                    <td><?= $s['mfa_verified_at'] !== null ? $h->badge('ok', $h->tr('verified')) : '—' ?></td>
                    <td class="actions">
                        <?php if ($can('sessions.revoke') && $mayAdminister): ?>
                            <form method="post" action="<?= $h->url('admin.users.sessions.revoke', ['id' => $user->id, 'sid' => $s['id']]) ?>" data-confirm="<?= $h->t('Terminate this session?') ?>"><?= $csrfInput ?><button class="btn btn--sm btn--ghost" type="submit" aria-label="<?= $h->t('Terminate session') ?>"><?= $h->icon('x') ?></button></form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Linked SSO identities') ?></h2><span class="small muted"><?= count($identities) ?></span></div>
    <?php if ($identities === []): ?>
        <div class="empty"><?= $h->t('No external identities are linked to this account.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th scope="col"><?= $h->t('Provider') ?></th><th scope="col"><?= $h->t('Issuer') ?></th><th scope="col"><?= $h->t('Subject') ?></th><th scope="col"><?= $h->t('E-mail') ?></th><th scope="col"><?= $h->t('Linked') ?></th><th scope="col"><?= $h->t('Last used') ?></th><th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($identities as $i):
                $subject = (string) $i['subject'];
                $masked = strlen($subject) > 6 ? substr($subject, 0, 3) . str_repeat('•', min(8, strlen($subject) - 5)) . substr($subject, -2) : $subject; ?>
                <tr>
                    <td><?= $h->e($i['provider_name'] ?? $i['provider_code']) ?> <span class="tag"><?= $h->e($i['provider_type'] ?? '?') ?></span></td>
                    <td class="small mono"><?= $h->e($i['issuer']) ?></td>
                    <td class="small mono"><?= $h->e($masked) ?></td>
                    <td class="small"><?= $h->e($i['email'] ?? '—') ?></td>
                    <td class="small nowrap"><?= $h->datetime($i['linked_at']) ?></td>
                    <td class="small nowrap"><?= $h->ago($i['last_used_at']) ?></td>
                    <td class="actions">
                        <?php if ($canManage): ?>
                            <form method="post" action="<?= $h->url('admin.users.identities.unlink', ['id' => $user->id, 'iid' => $i['id']]) ?>" data-confirm="<?= $h->t('Unlink this external identity? The user will no longer be able to sign in with it until it is linked again.') ?>"><?= $csrfInput ?><button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Unlink') ?></button></form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>
