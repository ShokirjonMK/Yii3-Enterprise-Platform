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
 */

$this->setTitle($h->tr('Security & sessions'));
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Security & sessions') ?></h1></div>
    <div class="page-head__actions">
        <?php if ($user->canUsePassword()): ?><a class="btn" href="<?= $h->url('auth.password.change') ?>"><?= $h->icon('lock') ?> <?= $h->t('Change password') ?></a><?php endif ?>
    </div>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Two-factor authentication') ?></h2><?= $h->badge($user->mfaEnabled ? 'enabled' : 'disabled') ?></div>
        <div class="card__body">
            <?php if ($user->mfaEnabled): ?>
                <p><?= $h->t('Recovery codes left: {n}', ['n' => count($user->mfaRecoveryCodes)]) ?></p>
                <div class="btn-group">
                    <form method="post" action="<?= $h->url('web.security.mfa.codes') ?>" data-confirm="<?= $h->t('Old recovery codes will stop working. Continue?') ?>"><?= $csrfField ?><button class="btn" type="submit"><?= $h->t('Regenerate recovery codes') ?></button></form>
                    <form method="post" action="<?= $h->url('web.security.mfa.disable') ?>" data-confirm="<?= $h->t('Disable two-factor authentication?') ?>"><?= $csrfField ?><button class="btn btn--danger" type="submit"><?= $h->t('Disable') ?></button></form>
                </div>
            <?php else: ?>
                <p class="muted"><?= $h->t('Protect your account with an authenticator app.') ?></p>
                <a class="btn btn--primary" href="<?= $h->url('auth.mfa.setup') ?>"><?= $h->t('Set up') ?></a>
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
