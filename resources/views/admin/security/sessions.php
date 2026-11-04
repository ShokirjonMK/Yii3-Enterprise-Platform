<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{q:string} $filters
 * @var string|null $current
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Sessions & tokens'));
$this->setParameter('breadcrumbs', [$h->tr('Security Center') => $h->url('admin.security'), $h->tr('Sessions & tokens')]);
$query = array_filter(['q' => $filters['q'], 'sort' => $page->sort]);
$post = static function (string $route, array $args, string $label, string $confirm, string $class = 'btn btn--sm') use ($h, $csrf): string {
    return '<form class="ops-inline" method="post" action="' . $h->e($h->url($route, $args)) . '" data-confirm="' . $h->e($confirm) . '">'
        . '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '"><button class="' . $h->e($class) . '" type="submit">' . $h->e($label) . '</button></form>';
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Security Center') ?></h1>
        <p class="page-head__sub"><?= $h->t('All active interactive sessions across users. Terminating a session signs the user out on the next request.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.security.sessions']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.security.sessions') ?>" hx-get="<?= $h->url('admin.security.sessions') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('User or IP') ?>" aria-label="<?= $h->t('Search') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
        <span class="small muted"><?= $h->t('{n} active sessions', ['n' => $page->total]) ?></span>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->t('No active sessions.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('User'), 'user', $page->sort, $query, 'admin.security.sessions') ?></th>
            <th><?= $h->t('Method') ?></th>
            <th><?= $h->sortLink($h->tr('IP'), 'ip', $page->sort, $query, 'admin.security.sessions') ?></th>
            <th><?= $h->t('Device') ?></th>
            <th><?= $h->sortLink($h->tr('Started'), 'created', $page->sort, $query, 'admin.security.sessions') ?></th>
            <th><?= $h->sortLink($h->tr('Last seen'), 'last_seen', $page->sort, $query, 'admin.security.sessions') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $s): $mine = $current !== null && hash_equals($current, (string) $s['id']); ?>
            <tr data-session="<?= $h->e($s['id']) ?>">
                <td><?= $h->e($s['full_name'] ?: $s['username']) ?><br><span class="small muted mono"><?= $h->e($s['username']) ?></span><?= $mine ? ' <span class="tag">' . $h->t('this session') . '</span>' : '' ?></td>
                <td><span class="tag"><?= $h->e($s['auth_method']) ?></span><?= $s['mfa_verified_at'] ? ' <span class="tag">MFA</span>' : '' ?></td>
                <td class="mono small"><?= $h->e($s['ip']) ?></td>
                <td class="small"><?= $h->e(mb_strimwidth((string) $s['user_agent'], 0, 60, '…')) ?></td>
                <td class="small nowrap"><?= $h->ago($s['created_at']) ?></td>
                <td class="small nowrap"><?= $h->ago($s['last_seen_at']) ?></td>
                <td class="actions">
                    <?php if ($can('sessions.revoke') && !$mine): ?>
                        <?= $post('admin.security.sessions.revoke', ['id' => $s['id']], $h->tr('Terminate'), $h->tr('Terminate this session?'), 'btn btn--sm btn--danger') ?>
                    <?php endif ?>
                    <details class="dropdown ops-inline"><summary class="btn btn--sm btn--ghost" aria-label="<?= $h->t('More') ?>">…</summary>
                        <div class="dropdown__menu">
                            <?php if ($can('sessions.revoke')): ?><?= $post('admin.security.sessions.revoke_user', ['id' => $s['user_id']], $h->tr('Terminate all sessions of this user'), $h->tr('Sign this user out everywhere?')) ?><?php endif ?>
                            <?php if ($can('security.manage')): ?><?= $post('admin.security.tokens.revoke', ['id' => $s['user_id']], $h->tr('Revoke all API tokens of this user'), $h->tr('Revoke every API token of this user?')) ?><?php endif ?>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.security.sessions', 'query' => $query]) ?>
</div>
