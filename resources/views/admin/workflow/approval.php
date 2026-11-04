<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $r
 * @var list<array<string,mixed>> $decisions
 * @var bool $canDecide
 * @var bool $isRequester
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($r['title']);
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow'), $h->tr('Approvals') => $h->url('admin.workflow.approvals'), substr((string) $r['id'], 0, 8)]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($r['title']) ?></h1>
    <p class="page-head__sub row"><span data-status="<?= $h->e($r['status']) ?>"><?= $h->badge($r['status']) ?></span> <span class="small muted"><?= $h->t('{a} of {n} approvals', ['a' => $r['approvals'], 'n' => $r['required_approvals']]) ?></span></p></div>
    <?php if ($r['status'] === 'pending' && ($isRequester || $can('workflow.manage'))): ?>
    <div class="page-head__actions"><form method="post" action="<?= $h->url('admin.workflow.approval_cancel', ['id' => $r['id']]) ?>" data-confirm="<?= $h->t('Withdraw this request?') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <button class="btn btn--danger" type="submit"><?= $h->icon('x') ?> <?= $h->t('Cancel request') ?></button></form></div>
    <?php endif ?>
</div>
<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card"><div class="card__body"><dl class="dl">
            <dt><?= $h->t('Type') ?></dt><dd class="mono"><?= $h->e($r['type']) ?></dd>
            <dt><?= $h->t('Entity') ?></dt><dd class="mono"><?= $h->e(($r['entity_type'] ?? '—') . ':' . ($r['entity_id'] ?? '')) ?></dd>
            <?php if ($r['instance_id']): ?><dt><?= $h->t('Workflow instance') ?></dt><dd><a href="<?= $h->url('admin.workflow.instance', ['id' => $r['instance_id']]) ?>"><?= $h->e($r['transition']) ?></a></dd><?php endif ?>
            <dt><?= $h->t('Requested by') ?></dt><dd><?= $h->e($r['requested_by_name'] ?? $r['requested_by']) ?></dd>
            <dt><?= $h->t('Approver permission') ?></dt><dd class="mono"><?= $h->e($r['approver_permission']) ?></dd>
            <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($r['created_at'], 'Y-m-d H:i') ?></dd>
            <dt><?= $h->t('Expires') ?></dt><dd><?= $h->datetime($r['expires_at']) ?></dd>
            <dt><?= $h->t('Decided') ?></dt><dd><?= $h->datetime($r['decided_at']) ?></dd>
        </dl><pre class="mt"><?= $h->json(Redactor::redact(Sql::json($r['payload']))) ?></pre></div></div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Decisions') ?></h2></div>
            <?php if ($decisions === []): ?><div class="card__body muted"><?= $h->t('No decisions yet.') ?></div><?php else: ?>
            <div class="card__body"><ol class="timeline"><?php foreach ($decisions as $d): ?>
                <li><?= $h->badge($d['decision'] === 'approve' ? 'approved' : 'rejected') ?> <strong><?= $h->e($d['approver_name'] ?? $d['approver_id']) ?></strong> <span class="small muted"><?= $h->datetime($d['created_at'], 'Y-m-d H:i') ?></span>
                    <?php if ($d['comment']): ?><div class="small"><?= $h->e($d['comment']) ?></div><?php endif ?></li>
            <?php endforeach ?></ol></div><?php endif ?>
        </div>
    </div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Your decision') ?></h2></div>
        <?php if ($canDecide): ?>
        <form class="card__body form" method="post" action="<?= $h->url('admin.workflow.decide', ['id' => $r['id']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field"><label for="d-comment"><?= $h->t('Comment (required when rejecting)') ?></label><textarea id="d-comment" name="comment" rows="4" maxlength="2000"></textarea></div>
            <div class="row"><button class="btn btn--primary" type="submit" name="decision" value="approve"><?= $h->icon('check') ?> <?= $h->t('Approve') ?></button>
                <button class="btn btn--danger" type="submit" name="decision" value="reject"><?= $h->icon('x') ?> <?= $h->t('Reject') ?></button></div>
        </form>
        <?php else: ?>
            <div class="card__body muted"><?= $r['status'] !== 'pending' ? $h->t('This request is closed.') : ($isRequester ? $h->t('Four-eyes principle: you cannot decide on your own request.') : $h->t('You cannot decide on this request (already decided or missing permission).')) ?></div>
        <?php endif ?>
    </div>
</div>
