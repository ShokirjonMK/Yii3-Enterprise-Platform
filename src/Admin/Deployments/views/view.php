<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $d
 * @var list<array<string,mixed>> $history
 * @var string $actorId
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Deployment {v}', ['v' => $d['version']]));
$this->setParameter('breadcrumbs', [$h->tr('CI/CD & Deployments') => $h->url('admin.deployments'), $d['environment'] . ' · ' . $d['version']]);
$own = (string) $d['requested_by'] === $actorId;
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><span class="mono"><?= $h->e($d['version']) ?></span> → <?= $h->e($d['environment']) ?></h1>
        <p class="page-head__sub"><?= $h->badge($d['status']) ?> · <?= $h->e($d['strategy']) ?> · <?= $h->t('requested by {u} {ago}', ['u' => $d['requester'] ?? '—', 'ago' => $h->ago($d['created_at'])]) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($d['status'] === 'running' && ($can('deploy.request') || $can('deploy.approve'))): ?>
            <form method="post" action="<?= $h->url('admin.deployments.refresh', ['id' => $d['id']]) ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Refresh status') ?></button></form>
        <?php endif ?>
        <?php if ($d['status'] === 'approved' && $can('deploy.approve')): ?>
            <form method="post" action="<?= $h->url('admin.deployments.trigger', ['id' => $d['id']]) ?>"><?= $csrfInput ?><button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Trigger pipeline') ?></button></form>
        <?php endif ?>
        <?php if ($d['status'] === 'succeeded' && $can('deploy.request')): ?>
            <form method="post" action="<?= $h->url('admin.deployments.rollback', ['id' => $d['id']]) ?>" data-confirm="<?= $h->t('Request a rollback to the previous successful version?') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Request rollback') ?></button></form>
        <?php endif ?>
    </div>
</div>

<?php if ($d['error']): ?><div class="alert alert--<?= $d['status'] === 'rejected' ? 'warning' : 'error' ?>"><?= $h->e($d['error']) ?></div><?php endif ?>

<div class="grid grid--sidebar">
    <div class="stack">
        <?php if ($d['status'] === 'requested'): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Approval (four-eyes)') ?></h2></div>
            <div class="card__body">
                <?php if (!$can('deploy.approve')): ?>
                    <p class="muted"><?= $h->t('Waiting for a user with the deploy.approve permission.') ?></p>
                <?php elseif ($own): ?>
                    <div class="alert alert--info" data-four-eyes="own"><?= $h->t('You requested this deployment, so you cannot decide it. Another approver must review it.') ?></div>
                <?php else: ?>
                    <div class="grid grid--2">
                        <form class="form" method="post" action="<?= $h->url('admin.deployments.approve', ['id' => $d['id']]) ?>" data-confirm="<?= $h->t('Approve and start the deployment pipeline?') ?>">
                            <?= $csrfInput ?>
                            <div class="field"><label for="a-c"><?= $h->t('Comment (optional)') ?></label><input id="a-c" type="text" name="comment" maxlength="500"></div>
                            <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Approve') ?></button>
                        </form>
                        <form class="form" method="post" action="<?= $h->url('admin.deployments.reject', ['id' => $d['id']]) ?>">
                            <?= $csrfInput ?>
                            <div class="field"><label for="r-r"><?= $h->t('Reason') ?></label><input id="r-r" type="text" name="reason" required maxlength="1000"></div>
                            <button class="btn btn--danger" type="submit"><?= $h->icon('x') ?> <?= $h->t('Reject') ?></button>
                        </form>
                    </div>
                <?php endif ?>
            </div>
        </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('History') ?></h2></div>
            <div class="card__body">
                <?php if ($history === []): ?><p class="muted"><?= $h->t('No events.') ?></p><?php else: ?>
                <ol class="timeline">
                    <?php foreach ($history as $e): ?>
                        <li><strong><code><?= $h->e($e['action']) ?></code></strong> <?= $h->badge($e['result']) ?>
                            <div class="small muted"><?= $h->datetime($e['occurred_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($e['actor_name'] ?? 'system') ?></div></li>
                    <?php endforeach ?>
                </ol>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body">
            <dl class="dl ops-dl-tight">
                <dt><?= $h->t('Environment') ?></dt><dd><?= $h->e($d['environment']) ?></dd>
                <dt><?= $h->t('Version') ?></dt><dd class="mono"><?= $h->e($d['version']) ?></dd>
                <dt><?= $h->t('Commit') ?></dt><dd class="mono small"><?= $h->e($d['commit_sha'] ?? '—') ?></dd>
                <dt><?= $h->t('Strategy') ?></dt><dd><?= $h->e($d['strategy']) ?></dd>
                <dt><?= $h->t('Requested by') ?></dt><dd><?= $h->e($d['requester'] ?? '—') ?></dd>
                <dt><?= $h->t('Approved by') ?></dt><dd><?= $h->e($d['approver'] ?? '—') ?></dd>
                <?php if ($d['rejecter']): ?><dt><?= $h->t('Rejected by') ?></dt><dd><?= $h->e($d['rejecter']) ?></dd><?php endif ?>
                <dt><?= $h->t('Pipeline') ?></dt><dd><?php if ($d['pipeline_url']): ?><a href="<?= $h->e($d['pipeline_url']) ?>" target="_blank" rel="noopener noreferrer">#<?= (int) $d['pipeline_id'] ?></a><?php else: ?>—<?php endif ?></dd>
                <?php if ($d['rollback_of']): ?><dt><?= $h->t('Rollback of') ?></dt><dd><a class="mono small" href="<?= $h->url('admin.deployments.view', ['id' => $d['rollback_of']]) ?>"><?= $h->e(substr((string) $d['rollback_of'], 0, 8)) ?></a></dd><?php endif ?>
                <dt><?= $h->t('Finished') ?></dt><dd><?= $h->datetime($d['finished_at']) ?></dd>
            </dl>
            <?php if (trim((string) $d['notes']) !== ''): ?><h3 class="mt"><?= $h->t('Notes') ?></h3><p class="small ops-prewrap"><?= $h->e($d['notes']) ?></p><?php endif ?>
        </div>
    </div>
</div>
