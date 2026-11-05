<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Platform\Identity\Domain\User $user
 * @var list<array<string,mixed>> $sessions
 * @var string $currentSession
 * @var list<array<string,mixed>> $tokens
 * @var string|null $newToken
 * @var list<array> $mfaMethods
 * @var App\Platform\Identity\Mfa\MfaPolicy $mfaPolicy
 * @var string|null $mfaPreferred
 * @var list<array> $trustedDevices
 */

use App\Platform\Identity\Mfa\MfaMethodType;

$this->setTitle($h->tr('Security & sessions'));
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Security & sessions') ?></h1></div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('web.login_history') ?>"><?= $h->icon('clock') ?> <?= $h->t('Login history') ?></a>
        <?php if ($user->canUsePassword()): ?><a class="btn" href="<?= $h->url('auth.password.change') ?>"><?= $h->icon('lock') ?> <?= $h->t('Change password') ?></a><?php endif ?>
    </div>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head">
            <h2><?= $h->t('Two-factor authentication') ?></h2>
            <span><?= $h->badge($user->mfaEnabled ? 'enabled' : 'disabled') ?><?= $mfaPolicy->required ? ' ' . $h->badge('warning', $h->tr('mandatory')) : '' ?></span>
        </div>
        <div class="card__body">
            <?php if ($mfaMethods !== []): ?>
                <div class="mfa-methods mb">
                <?php foreach ($mfaMethods as $m): ?>
                    <div class="mfa-method">
                        <?= $h->icon(MfaMethodType::ICONS[$m['type']] ?? 'key') ?>
                        <div class="mfa-method__main">
                            <strong><?= $h->t(MfaMethodType::label($m['type'])) ?></strong><?= $m['type'] === $mfaPreferred ? ' ' . $h->badge('info', $h->tr('default')) : '' ?><?= !$mfaPolicy->allows($m['type']) ? ' ' . $h->badge('muted', $h->tr('not allowed by policy')) : '' ?>
                            <small><?= $h->e(trim($m['label'] !== MfaMethodType::label($m['type']) ? $m['label'] : '')) ?><?= $m['target_hint'] !== '' && $m['target_hint'] !== $m['label'] ? ' · ' . $h->e($m['target_hint']) : '' ?><?= $m['last_used_at'] ? ' · ' . $h->t('used {ago}', ['ago' => $h->ago($m['last_used_at'])]) : '' ?></small>
                        </div>
                        <?php if ($m['type'] !== $mfaPreferred && $mfaPolicy->allows($m['type'])): ?>
                            <form method="post" action="<?= $h->url('web.security.mfa.preferred') ?>"><?= $csrfField ?><input type="hidden" name="method" value="<?= $h->e($m['type']) ?>"><button class="btn btn--sm btn--ghost" type="submit"><?= $h->t('Make default') ?></button></form>
                        <?php endif ?>
                        <form method="post" action="<?= $h->url('web.security.mfa.remove', ['id' => $m['id']]) ?>" data-confirm="<?= $h->t('Remove this method?') ?>"><?= $csrfField ?><button class="btn btn--sm btn--danger" type="submit" aria-label="<?= $h->t('Remove') ?>"><?= $h->icon('trash', 'icon--sm') ?></button></form>
                    </div>
                <?php endforeach ?>
                </div>
            <?php else: ?>
                <p class="muted"><?= $h->t('Protect your account with a second factor: authenticator app, passkey, Telegram, e-mail, SMS, voice call or E-IMZO.') ?></p>
            <?php endif ?>
            <div class="btn-group">
                <a class="btn btn--primary" href="<?= $h->url('auth.mfa.setup') ?>"><?= $h->icon('plus') ?> <?= $h->t($mfaMethods === [] ? 'Set up' : 'Add method') ?></a>
                <?php if ($user->mfaEnabled): ?>
                    <form method="post" action="<?= $h->url('web.security.mfa.codes') ?>" data-confirm="<?= $h->t('Old recovery codes will stop working. Continue?') ?>"><?= $csrfField ?><button class="btn" type="submit"><?= $h->t('Regenerate recovery codes') ?> (<?= count($user->mfaRecoveryCodes) ?>)</button></form>
                    <?php if (!$mfaPolicy->required): ?>
                        <form method="post" action="<?= $h->url('web.security.mfa.disable') ?>" data-confirm="<?= $h->t('Disable two-factor authentication and remove all methods?') ?>"><?= $csrfField ?><button class="btn btn--danger" type="submit"><?= $h->t('Disable') ?></button></form>
                    <?php endif ?>
                <?php endif ?>
            </div>
            <?php if ($mfaPolicy->required): ?>
                <?php $byRoles = array_values(array_filter($mfaPolicy->requiredBy, static fn($s) => $s !== 'account')); ?>
                <div class="alert alert--info mt small"><?= $h->icon('info') ?>
                    <div>
                        <strong><?= $h->t('Two-factor authentication is mandatory, so it cannot be switched off here.') ?></strong>
                        <?php if (in_array('account', $mfaPolicy->requiredBy, true)): ?>
                            <div><?= $h->t('Required for your account (setting "Require two-factor authentication" of the user).') ?>
                                <?php if ($can('users.manage')): ?><a href="<?= $h->url('admin.users.edit', ['id' => $user->id]) ?>"><?= $h->t('Change in Admin → Users') ?></a><?php endif ?></div>
                        <?php endif ?>
                        <?php if ($byRoles !== []): ?>
                            <div><?= $h->t('Required by the two-factor policy of the role(s): {roles}.', ['roles' => implode(', ', $byRoles)]) ?>
                                <?php if ($can('auth.manage')): ?><a href="<?= $h->url('admin.auth.mfa') ?>#policies"><?= $h->t('Change in Admin → Authentication → Two-factor') ?></a><?php endif ?></div>
                        <?php endif ?>
                    </div>
                </div>
            <?php endif ?>
            <?php if ($trustedDevices !== []): ?>
                <h3 class="mt"><?= $h->t('Remembered devices') ?></h3>
                <table class="table table--compact"><tbody>
                <?php foreach ($trustedDevices as $d): ?>
                    <tr>
                        <td><?= $h->e(mb_strimwidth((string) $d['user_agent'], 0, 50, '…')) ?><br><small class="muted"><?= $h->e((string) $d['ip']) ?> · <?= $h->t('until {date}', ['date' => $h->datetime($d['expires_at'], 'Y-m-d')]) ?></small></td>
                        <td class="actions"><form method="post" action="<?= $h->url('web.security.mfa.device.revoke', ['id' => $d['id']]) ?>"><?= $csrfField ?><button class="btn btn--sm" type="submit"><?= $h->t('Forget') ?></button></form></td>
                    </tr>
                <?php endforeach ?>
                </tbody></table>
            <?php endif ?>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Personal API tokens') ?></h2></div>
        <div class="card__body">
            <?php if ($newToken): ?>
                <div class="alert alert--warning"><div><strong><?= $h->t('Copy your new token now:') ?></strong><br><code><?= $h->e($newToken) ?></code> <button type="button" class="btn btn--sm" data-copy="<?= $h->e($newToken) ?>"><?= $h->t('Copy') ?></button></div></div>
            <?php endif ?>
            <form method="post" action="<?= $h->url('web.security.token.create') ?>" class="form form--2">
                <?= $csrfField ?>
                <div class="field"><label for="tname"><?= $h->t('Name') ?></label><input id="tname" name="name" required maxlength="128" placeholder="ci-pipeline"></div>
                <div class="field"><label for="tttl"><?= $h->t('Lifetime (days)') ?></label><input id="tttl" name="ttl_days" type="number" min="1" max="366" value="90"></div>
                <div class="field span-2"><label for="tscopes"><?= $h->t('Scopes') ?></label><input id="tscopes" name="scopes" value="projects.*" placeholder="projects.read, users.read"><div class="field__hint"><?= $h->t('Comma separated; "*" = all your permissions.') ?></div></div>
                <div class="span-2"><button class="btn btn--primary" type="submit"><?= $h->t('Create token') ?></button></div>
            </form>
        </div>
        <?php if ($tokens !== []): ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th><?= $h->t('Name') ?></th><th><?= $h->t('Expires') ?></th><th><?= $h->t('Last used') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= $h->e($t['name']) ?><?= $t['revoked_at'] ? ' ' . $h->badge('revoked') : '' ?></td>
                    <td class="nowrap"><?= $h->datetime($t['expires_at'], 'Y-m-d') ?></td>
                    <td class="nowrap"><?= $h->ago($t['last_used_at']) ?></td>
                    <td class="actions"><?php if (!$t['revoked_at']): ?><form method="post" action="<?= $h->url('web.security.token.revoke', ['id' => $t['id']]) ?>" data-confirm="<?= $h->t('Revoke this token?') ?>"><?= $csrfField ?><button class="btn btn--sm btn--danger"><?= $h->t('Revoke') ?></button></form><?php endif ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
    </div>
</div>

<div class="card mt">
    <div class="card__head">
        <h2><?= $h->t('Active sessions') ?></h2>
        <form method="post" action="<?= $h->url('web.security.sessions.revoke_others') ?>" data-confirm="<?= $h->t('Sign out all other devices?') ?>"><?= $csrfField ?><button class="btn btn--sm" type="submit"><?= $h->t('Sign out other devices') ?></button></form>
    </div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Device') ?></th><th><?= $h->t('IP') ?></th><th><?= $h->t('Method') ?></th><th><?= $h->t('Signed in') ?></th><th><?= $h->t('Last activity') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><?= $h->e(mb_strimwidth((string) $s['user_agent'], 0, 70, '…')) ?><?= $s['id'] === $currentSession ? ' ' . $h->badge('active', $h->tr('this device')) : '' ?></td>
                <td class="mono"><?= $h->e($s['ip']) ?></td>
                <td><?= $h->e($s['auth_method']) ?></td>
                <td class="nowrap"><?= $h->datetime($s['created_at']) ?></td>
                <td class="nowrap"><?= $h->ago($s['last_seen_at']) ?></td>
                <td class="actions"><?php if ($s['id'] !== $currentSession): ?><form method="post" action="<?= $h->url('web.security.session.revoke', ['id' => $s['id']]) ?>"><?= $csrfField ?><button class="btn btn--sm btn--danger"><?= $h->t('Terminate') ?></button></form><?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
