<?php

declare(strict_types=1);

/**
 * Admin · Project detail: attributes, lifecycle actions, members, activity timeline.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Modules\Projects\Domain\Project $project
 * @var list<array> $members
 * @var array{source:string, items:list<array>} $activity
 * @var bool $canManage
 * @var list<App\Modules\Projects\Domain\ProjectStatus> $transitions
 * @var string|null $pendingApproval
 * @var bool $archiveNeedsApproval
 */

use App\Modules\Projects\Domain\ProjectStatus;

$this->setTitle($project->code() . ' · ' . $project->name());
$this->setParameter('breadcrumbs', [$h->tr('Projects') => $h->url('admin.projects'), $project->code()]);
$status = $project->status();
$labels = [
    'active' => $status === ProjectStatus::Draft ? 'Approve & activate' : 'Resume',
    'on_hold' => 'Put on hold',
    'completed' => 'Complete',
];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><span class="mono"><?= $h->e($project->code()) ?></span> · <?= $h->e($project->name()) ?></h1>
        <p class="page-head__sub"><?= $h->badge($status->value, $h->tr($status->label())) ?> <span class="project-priority project-priority--<?= $h->e($project->priority()->value) ?>"><?= $h->t(ucfirst($project->priority()->value)) ?></span> · <?= $h->t('version {v}', ['v' => $project->version()]) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($canManage && !$status->isTerminal()): ?>
            <?php if ($can('projects.update')): ?>
                <a class="btn" href="<?= $h->url('admin.projects.edit', ['id' => $project->id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
                <?php foreach ($transitions as $t): ?>
                    <?php if ($t === ProjectStatus::Archived) {
                        continue;
                    } ?>
                    <?php if ($status === ProjectStatus::Draft && $t === ProjectStatus::Active && !$can('projects.approve')) {
                        continue;
                    } ?>
                    <form method="post" action="<?= $h->url('admin.projects.status', ['id' => $project->id]) ?>">
                        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                        <input type="hidden" name="version" value="<?= $project->version() ?>">
                        <input type="hidden" name="status" value="<?= $h->e($t->value) ?>">
                        <button class="btn<?= $t === ProjectStatus::Active ? ' btn--primary' : '' ?>" type="submit" data-transition="<?= $h->e($t->value) ?>"><?= $h->t($labels[$t->value] ?? $t->label()) ?></button>
                    </form>
                <?php endforeach ?>
            <?php endif ?>
            <?php if ($can('projects.delete') && $status->canTransitionTo(ProjectStatus::Archived) && $pendingApproval === null): ?>
                <form method="post" action="<?= $h->url('admin.projects.archive', ['id' => $project->id]) ?>" data-confirm="<?= $archiveNeedsApproval ? $h->t('Request archiving? An approver must confirm it (four-eyes).') : $h->t('Archive this project? Archived projects become read-only.') ?>">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <input type="hidden" name="version" value="<?= $project->version() ?>">
                    <button class="btn btn--danger" type="submit"><?= $h->icon('archive') ?> <?= $archiveNeedsApproval ? $h->t('Request archive') : $h->t('Archive') ?></button>
                </form>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>

<?php if ($pendingApproval !== null): ?>
    <?php $approvalUrl = $h->urlOrNull('admin.workflow.approvals.view', ['id' => $pendingApproval]); ?>
    <div class="alert alert--info mb" data-pending-approval="<?= $h->e($pendingApproval) ?>">
        <?= $h->t('Archiving is waiting for approval (workflow project.lifecycle).') ?>
        <?php if ($approvalUrl): ?><a href="<?= $h->e($approvalUrl) ?>"><?= $h->t('Open approval request') ?></a><?php else: ?><span class="mono small"><?= $h->e($pendingApproval) ?></span><?php endif ?>
    </div>
<?php endif ?>
<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt><?= $h->t('Description') ?></dt><dd><?= $project->description() === '' ? '<span class="muted">—</span>' : nl2br($h->e($project->description())) ?></dd>
                    <dt><?= $h->t('Budget') ?></dt><dd class="mono"><?= $project->budget() === null ? '—' : $h->e(number_format((float) $project->budget(), 2, '.', ' ')) ?></dd>
                    <dt><?= $h->t('Start date') ?></dt><dd><?= $h->e($project->startsOn() ?? '—') ?></dd>
                    <dt><?= $h->t('Due date') ?></dt><dd><?= $h->e($project->dueOn() ?? '—') ?></dd>
                    <dt><?= $h->t('Tags') ?></dt><dd><?php foreach ($project->tags() as $t): ?><span class="tag"><?= $h->e($t) ?></span><?php endforeach ?><?= $project->tags() === [] ? '—' : '' ?></dd>
                    <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($project->createdAt) ?></dd>
                    <dt><?= $h->t('Updated') ?></dt><dd><?= $h->datetime($project->updatedAt()) ?></dd>
                    <?php if ($project->archivedAt() !== null): ?><dt><?= $h->t('Archived') ?></dt><dd><?= $h->datetime($project->archivedAt()) ?></dd><?php endif ?>
                    <dt><?= $h->t('Next states') ?></dt><dd><?= $transitions === [] ? '—' : $h->e(implode(', ', array_map(static fn($s) => $s->value, $transitions))) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card" id="project-activity">
            <div class="card__head"><h2><?= $h->t('Activity') ?></h2><span class="small muted"><?= $activity['source'] === 'read_model' ? $h->t('Kafka read model (projects-audit)') : $h->t('Audit trail (read model not populated yet)') ?></span></div>
            <?php if ($activity['items'] === []): ?>
                <div class="empty"><?= $h->t('No activity yet.') ?></div>
            <?php else: ?>
                <ul class="timeline card__body">
                    <?php foreach ($activity['items'] as $a): ?>
                        <li>
                            <div class="row row--between"><code><?= $h->e($a['event_type']) ?></code><span class="small muted"><?= $h->ago($a['occurred_at']) ?></span></div>
                            <div><?= $h->e($a['summary']) ?></div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Members') ?></h2></div>
        <div class="table-wrap"><table class="table table--compact">
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= $h->e($m['full_name'] ?: $m['username']) ?><div class="small muted"><?= $h->e($m['username']) ?></div></td>
                    <td><span class="tag"><?= $h->e($m['role']) ?></span></td>
                    <td class="right">
                        <?php if ($canManage && $m['role'] !== 'owner' && $can('projects.update') && !$status->isTerminal()): ?>
                            <form method="post" action="<?= $h->url('admin.projects.members.remove', ['id' => $project->id, 'userId' => $m['user_id']]) ?>" data-confirm="<?= $h->t('Remove this member?') ?>">
                                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                                <button class="btn btn--sm btn--ghost" type="submit" aria-label="<?= $h->t('Remove') ?>"><?= $h->icon('x', 'icon--sm') ?></button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php if ($canManage && $can('projects.update') && !$status->isTerminal()): ?>
            <form class="form card__body" method="post" action="<?= $h->url('admin.projects.members.add', ['id' => $project->id]) ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <div class="field"><label for="m-login"><?= $h->t('Add member (username or e-mail)') ?></label><input id="m-login" type="text" name="login" required></div>
                <div class="field"><label for="m-role"><?= $h->t('Role') ?></label>
                    <select id="m-role" name="role"><option value="member"><?= $h->t('Member') ?></option><option value="manager"><?= $h->t('Manager') ?></option><option value="viewer"><?= $h->t('Viewer') ?></option></select>
                </div>
                <button class="btn btn--sm" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Add') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>
