<?php

declare(strict_types=1);

/**
 * 03 · Authentication → Two-factor.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string, array{enabled:bool, configured:bool, users:int, provider:?array}> $methods
 * @var array<string, array{required:bool, allowed:list<string>, remember_days:int, updated_at?:string}> $policies
 * @var array<string, array{name:string, description:string, system:bool, admin:bool}> $roles
 * @var array<string,bool> $mayEdit
 * @var bool $isSuper
 * @var array{total:int, mfa:int} $coverage
 * @var string $telegramMode
 * @var string $csrf
 * @var callable(string):bool $can
 */

use App\Platform\Identity\Mfa\MfaMethodType;

$this->setTitle($h->tr('Two-factor authentication'));
$this->setParameter('breadcrumbs', [$h->tr('Authentication') => $h->url('admin.auth'), $h->tr('Two-factor')]);
$manage = $can('auth.manage');
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$pct = $coverage['total'] > 0 ? (int) round($coverage['mfa'] * 100 / $coverage['total']) : 0;
$withoutPolicy = array_diff_key($roles, $policies);
$policyForm = static function (string $subject, array $p, bool $editable, bool $isNew) use ($h, $csrfField): string {
    ob_start(); ?>
    <form method="post" action="<?= $h->url('admin.auth.mfa.policy') ?>" class="mfa-policy">
        <?= $csrfField ?>
        <?php if ($isNew): ?>
            <select name="subject" required aria-label="<?= $h->t('Role') ?>"><?= $subject ?></select>
        <?php else: ?>
            <input type="hidden" name="subject" value="<?= $h->e($subject) ?>">
        <?php endif ?>
        <label class="check"><input type="checkbox" name="required" value="1"<?= $p['required'] ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>> <?= $h->t('Required') ?></label>
        <span class="mfa-policy__methods">
        <?php foreach (MfaMethodType::ALL as $t): ?>
            <label class="check small"><input type="checkbox" name="allowed[]" value="<?= $h->e($t) ?>"<?= in_array($t, $p['allowed'], true) ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>> <?= $h->t(MfaMethodType::label($t)) ?></label>
        <?php endforeach ?>
        </span>
        <label class="small"><?= $h->t('Remember device (days)') ?> <input type="number" name="remember_days" min="0" max="90" value="<?= (int) $p['remember_days'] ?>" class="input--xs"<?= $editable ? '' : ' disabled' ?>></label>
        <?php if ($editable): ?><button class="btn btn--sm btn--primary" type="submit"><?= $h->t($isNew ? 'Add policy' : 'Save') ?></button><?php endif ?>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Authentication') ?></h1>
        <p class="page-head__sub"><?= $h->t('Second factors, who must use them and how they are delivered') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.auth.mfa']) ?>

<div class="grid grid--3 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Active users with 2FA') ?></div><div class="kpi__value"><?= $coverage['mfa'] ?> / <?= $coverage['total'] ?></div><div class="bar w-<?= max(0, min(100, (int) (round($pct / 5) * 5))) ?>"></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Methods available') ?></div><div class="kpi__value"><?= count(array_filter($methods, static fn($m) => $m['enabled'] && $m['configured'])) ?> / <?= count($methods) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Roles that require 2FA') ?></div><div class="kpi__value"><?= count(array_filter($policies, static fn($p) => $p['required'])) ?></div></div>
</div>

<div class="card mb">
    <div class="card__head"><h2><?= $h->t('Methods') ?></h2><span class="small muted"><?= $h->t('Switching methods on/off: superadmin only') ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Method') ?></th><th><?= $h->t('State') ?></th><th><?= $h->t('Provider') ?></th><th><?= $h->t('Users') ?></th><th><span class="sr-only"><?= $h->t('Actions') ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($methods as $t => $m): ?>
            <tr>
                <td><?= $h->icon(MfaMethodType::ICONS[$t] ?? 'key') ?> <strong><?= $h->t(MfaMethodType::label($t)) ?></strong></td>
                <td><?= $h->badge($m['enabled'] ? 'enabled' : 'disabled') ?></td>
                <td><?= $m['configured'] ? $h->badge('ok', $h->tr('configured')) : $h->badge('unconfigured', $h->tr('Not configured')) ?>
                    <?php if ($m['provider'] !== null): ?><br><a class="small" href="<?= $h->url($m['provider'][0]) ?>"><?= $h->t($m['provider'][1]) ?></a><?php elseif (in_array($t, ['voice', 'eimzo'], true)): ?><br><a class="small" href="<?= $h->url('admin.auth.mfa.settings') ?>"><?= $h->t('Configure in 2FA settings') ?></a><?php endif ?></td>
                <td><?= (int) $m['users'] ?></td>
                <td class="actions">
                    <?php if ($manage): ?>
                        <form method="post" action="<?= $h->url('admin.auth.mfa.check', ['type' => $t]) ?>" class="inline-form">
                            <?= $csrfField ?>
                            <?php if (MfaMethodType::isOtp($t)): ?><input name="target" class="input--sm" placeholder="<?= $h->e(match ($t) { 'email' => 'test@example.com', 'telegram' => 'chat id', default => '+998901234567' }) ?>" aria-label="<?= $h->t('Test recipient (optional)') ?>"><?php endif ?>
                            <button class="btn btn--sm" type="submit"><?= $h->icon('activity', 'icon--sm') ?> <?= $h->t(MfaMethodType::isOtp($t) ? 'Check / test send' : 'Check') ?></button>
                        </form>
                    <?php endif ?>
                    <?php if ($isSuper): ?>
                        <form method="post" action="<?= $h->url('admin.auth.mfa.toggle', ['type' => $t]) ?>" class="inline" data-confirm="<?= $h->t($m['enabled'] ? 'Switch this method off? Users relying on it are asked to set up another one.' : 'Switch this method on?') ?>"><?= $csrfField ?><button class="btn btn--sm<?= $m['enabled'] ? ' btn--danger' : ' btn--primary' ?>" type="submit"><?= $h->t($m['enabled'] ? 'Disable' : 'Enable') ?></button></form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>

<div class="card mb">
    <div class="card__head"><h2><?= $h->t('Policies by role') ?></h2></div>
    <div class="card__body">
        <p class="small muted"><?= $h->t('A user with several roles must satisfy all of them: required if any role requires it, only methods allowed by every role, the shortest remember-device period. "*" applies to users whose roles have no policy. Policies of administrative roles can be changed only by a superadmin.') ?></p>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $h->t('Role') ?></th><th><?= $h->t('Policy') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($policies as $subject => $p): $editable = $manage && ($mayEdit[$subject] ?? false); ?>
                <tr>
                    <td class="nowrap"><strong><?= $h->e($subject === '*' ? $h->tr('* (everyone else)') : $subject) ?></strong>
                        <?php if (($roles[$subject]['admin'] ?? false)): ?><br><?= $h->badge('warning', $h->tr('administrative')) ?><?php endif ?></td>
                    <td><?= $policyForm($subject, $p, $editable, false) ?></td>
                    <td class="actions"><?php if ($editable && $subject !== '*'): ?>
                        <form method="post" action="<?= $h->url('admin.auth.mfa.policy.delete', ['subject' => $subject]) ?>" data-confirm="<?= $h->t('Remove this policy? The role will follow the default policy.') ?>"><?= $csrfField ?><button class="btn btn--sm btn--ghost" type="submit"><?= $h->icon('trash', 'icon--sm') ?></button></form>
                    <?php endif ?></td>
                </tr>
            <?php endforeach ?>
            <?php if ($manage && $withoutPolicy !== []):
                $options = '';
                foreach ($withoutPolicy as $name => $r) {
                    if (!$r['admin'] || $isSuper) {
                        $options .= '<option value="' . $h->e($name) . '">' . $h->e($name) . ($r['admin'] ? ' ★' : '') . '</option>';
                    }
                }
                if ($options !== ''): ?>
                <tr><td class="nowrap"><em><?= $h->t('New policy') ?></em></td><td><?= $policyForm($options, ['required' => true, 'allowed' => ['totp', 'webauthn', 'telegram'], 'remember_days' => 0], true, true) ?></td><td></td></tr>
            <?php endif; endif ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Telegram bot updates') ?></h2><?= $h->badge('info', $telegramMode) ?></div>
    <div class="card__body">
        <p class="small muted"><?= $h->t('Polling works anywhere (also on localhost) and is checked while a user is connecting Telegram. A webhook is faster but needs this site on a public HTTPS address.') ?></p>
        <?php if ($manage): ?>
        <div class="btn-group">
            <form method="post" action="<?= $h->url('admin.auth.mfa.telegram') ?>"><?= $csrfField ?><input type="hidden" name="action" value="info"><button class="btn btn--sm" type="submit"><?= $h->t('Webhook status') ?></button></form>
            <form method="post" action="<?= $h->url('admin.auth.mfa.telegram') ?>"><?= $csrfField ?><input type="hidden" name="action" value="set"><button class="btn btn--sm" type="submit"><?= $h->t('Use webhook (this URL)') ?></button></form>
            <form method="post" action="<?= $h->url('admin.auth.mfa.telegram') ?>"><?= $csrfField ?><input type="hidden" name="action" value="delete"><button class="btn btn--sm" type="submit"><?= $h->t('Use polling') ?></button></form>
        </div>
        <?php endif ?>
    </div>
</div>
