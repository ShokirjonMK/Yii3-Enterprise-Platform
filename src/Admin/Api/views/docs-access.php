<?php

declare(strict_types=1);

/**
 * API Management → Docs access (/admin/api/docs).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $passwords
 * @var array<string,int> $usage
 * @var array<string,mixed> $values
 * @var array<string,list<string>> $errors
 * @var array{id:string,password:string,expires_at:string,label:string}|null $issued
 * @var int $maxDays
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('API docs access'));
$this->setParameter('breadcrumbs', [$h->tr('API Management') => $h->url('admin.api'), $h->tr('Docs access')]);
$manage = $can('api.docs.manage');
$fieldError = static function (string $name) use ($h, $errors): string {
    $out = '';
    foreach ($errors[$name] ?? [] as $e) {
        $out .= '<div class="field__error" id="' . $name . '-err">' . $h->t($e) . '</div>';
    }
    return $out;
};
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('API docs access') ?></h1>
    <p class="page-head__sub"><?= $h->t('The API documentation (/api/docs) is private. Signed-in users see it when one of their roles has the api.docs.view permission; anybody else needs an access password issued here.') ?></p>
</div>
<div class="page-head__actions"><a class="btn" href="/api/docs"><?= $h->icon('file') ?> <?= $h->t('Open API docs') ?></a></div></div>

<?= $this->render('./_usage', ['usage' => $usage, 'active' => 'docs']) ?>

<?php if ($issued !== null): ?>
<div class="card id-secret mb">
    <div class="card__body stack">
        <div class="alert alert--warning" role="alert"><?= $h->icon('alert') ?> <span><?= $h->t('Copy the password now. It is shown only once; the platform stores only an irreversible hash.') ?></span></div>
        <dl class="dl">
            <dt><?= $h->t('For') ?></dt><dd><?= $h->e($issued['label']) ?></dd>
            <dt><?= $h->t('Access password') ?></dt>
            <dd class="id-secret__value"><code class="mono" data-docs-password><?= $h->e($issued['password']) ?></code> <button type="button" class="btn btn--sm" data-copy="<?= $h->e($issued['password']) ?>"><?= $h->t('Copy') ?></button></dd>
            <dt><?= $h->t('Valid until') ?></dt><dd><?= $h->datetime($issued['expires_at']) ?></dd>
        </dl>
        <p class="muted"><?= $h->t('Send it through a separate channel from the link. One browser session stays unlocked for 12 hours.') ?></p>
    </div>
</div>
<?php endif ?>

<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Access passwords') ?></h2></div>
        <?php if ($passwords === []): ?>
            <div class="empty"><?= $h->icon('lock') ?><p><?= $h->t('No access passwords yet.') ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $h->t('For') ?></th><th><?= $h->t('Ends with') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Valid until') ?></th><th><?= $h->t('Last used') ?></th><th><?= $h->t('Uses') ?></th><th><?= $h->t('Issued by') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($passwords as $p): ?>
                <tr data-docs-password-row="<?= $h->e($p['id']) ?>">
                    <td><?= $h->e($p['label']) ?></td>
                    <td class="mono">…<?= $h->e($p['hint']) ?></td>
                    <td><?= $h->badge((string) $p['status']) ?></td>
                    <td><?= $h->datetime((string) $p['expires_at']) ?></td>
                    <td><?= $p['last_used_at'] ? $h->ago((string) $p['last_used_at']) : '—' ?></td>
                    <td><?= (int) $p['use_count'] ?></td>
                    <td><?= $h->e((string) ($p['creator'] ?? '—')) ?></td>
                    <td>
                        <?php if ($manage && $p['status'] === 'active'): ?>
                        <form method="post" action="<?= $h->url('admin.api.docs.revoke', ['id' => $p['id']]) ?>" data-confirm="<?= $h->t('Revoke this password?') ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Revoke') ?></button>
                        </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
    </div>

    <div class="stack">
        <?php if ($manage): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Issue a password') ?></h2></div>
            <div class="card__body">
                <form method="post" action="<?= $h->url('admin.api.docs.issue') ?>" class="form">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <div class="field<?= isset($errors['label']) ? ' field--invalid' : '' ?>">
                        <label for="docs-label"><?= $h->t('Who is it for') ?></label>
                        <input id="docs-label" name="label" maxlength="120" required value="<?= $h->e((string) ($values['label'] ?? '')) ?>" placeholder="<?= $h->t('e.g. Mobile team, partner company') ?>">
                        <?= $fieldError('label') ?>
                    </div>
                    <div class="field<?= isset($errors['days']) ? ' field--invalid' : '' ?>">
                        <label for="docs-days"><?= $h->t('Valid for (days)') ?></label>
                        <input id="docs-days" name="days" type="number" min="1" max="<?= $maxDays ?>" required value="<?= $h->e((string) ($values['days'] ?? 30)) ?>">
                        <?= $fieldError('days') ?>
                    </div>
                    <button class="btn btn--primary" type="submit"><?= $h->icon('key') ?> <?= $h->t('Generate password') ?></button>
                </form>
            </div>
        </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Access for users') ?></h2></div>
            <div class="card__body">
                <p><?= $h->t('Give a role the api.docs.view permission in RBAC. The developer and admin roles have it by default; superadmin has every permission.') ?></p>
                <?php if ($can('rbac.view')): ?><a class="btn btn--sm" href="<?= $h->url('admin.rbac') ?>"><?= $h->icon('shield') ?> <?= $h->t('Roles & permissions') ?></a><?php endif ?>
            </div>
        </div>
    </div>
</div>
