<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $instance
 * @var array $wf
 * @var App\Platform\Workflow\WorkflowDefinition $def
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $transitions
 * @var list<array<string,mixed>> $approvals
 * @var string $csrf
 */
$this->setTitle($instance['entity_type'] . ':' . $instance['entity_id']);
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow'), $h->tr('Instances') => $h->url('admin.workflow.instances'), $instance['entity_type'] . ':' . $instance['entity_id']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><span class="mono"><?= $h->e($instance['entity_type'] . ':' . $instance['entity_id']) ?></span></h1>
    <p class="page-head__sub row"><a class="tag" href="<?= $h->url('admin.workflow.show', ['id' => $wf['id']]) ?>"><?= $h->e($wf['code']) ?></a> <?= $h->t('State') ?>: <strong data-state="<?= $h->e($instance['state']) ?>"><?= $h->e($def->label((string) $instance['state'])) ?></strong> <span class="small muted">v<?= (int) $instance['version'] ?></span></p></div></div>
<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('History') ?></h2></div><div class="card__body">
            <ol class="timeline">
                <?php foreach ($history as $t): ?>
                    <li><strong><?= $h->e($t['transition']) ?></strong> <?= $t['from_state'] !== '' ? '<span class="tag">' . $h->e($t['from_state']) . '</span> → ' : '' ?><span class="tag"><?= $h->e($t['to_state']) ?></span>
                        <div class="small muted"><?= $h->datetime($t['created_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($t['actor_name'] ?? 'system') ?></div>
                        <?php if ($t['comment']): ?><div class="small"><?= $h->e($t['comment']) ?></div><?php endif ?></li>
                <?php endforeach ?>
            </ol>
        </div></div>
        <?php if ($approvals !== []): ?>
        <div class="card"><div class="card__head"><h2><?= $h->t('Approval requests') ?></h2></div>
            <div class="table-wrap"><table class="table table--compact"><tbody>
                <?php foreach ($approvals as $a): ?><tr><td><a href="<?= $h->url('admin.workflow.approval', ['id' => $a['id']]) ?>"><?= $h->e($a['transition']) ?></a></td><td><?= $h->badge($a['status']) ?></td><td class="small"><?= (int) $a['approvals'] ?>/<?= (int) $a['required_approvals'] ?></td><td class="small"><?= $h->ago($a['created_at']) ?></td></tr><?php endforeach ?>
            </tbody></table></div></div>
        <?php endif ?>
    </div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Available transitions') ?></h2></div>
        <?php if ($transitions === []): ?>
            <div class="card__body muted"><?= $h->t('No transitions available to you from this state.') ?></div>
        <?php else: ?>
        <form class="card__body form" method="post" action="<?= $h->url('admin.workflow.apply', ['id' => $instance['id']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="version" value="<?= (int) $instance['version'] ?>">
            <?php foreach ($transitions as $i => $t): ?>
                <label class="check"><input type="radio" name="transition" value="<?= $h->e($t['name']) ?>"<?= $i === 0 ? ' checked' : '' ?><?= $t['pending_approval_id'] ? ' disabled' : '' ?>>
                    <?= $h->e($t['label']) ?> → <span class="tag"><?= $h->e($t['to']) ?></span>
                    <?= $t['requires_approval'] ? $h->badge($t['pending_approval_id'] ? 'pending' : 'info', $t['pending_approval_id'] ? $h->tr('approval pending') : $h->tr('needs approval')) : '' ?></label>
            <?php endforeach ?>
            <div class="field"><label for="apply-comment"><?= $h->t('Comment') ?></label><textarea id="apply-comment" name="comment" rows="3" maxlength="2000"></textarea></div>
            <button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Apply') ?></button>
        </form>
        <?php endif ?>
    </div>
</div>
