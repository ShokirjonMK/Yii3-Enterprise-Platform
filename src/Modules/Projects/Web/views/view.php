<?php

declare(strict_types=1);

/**
 * Web · Project detail for end users.
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
 */

use App\Modules\Projects\Domain\ProjectStatus;

$this->setTitle($project->name());
$status = $project->status();
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($project->name()) ?></h1>
        <p class="page-head__sub"><span class="mono"><?= $h->e($project->code()) ?></span> · <?= $h->badge($status->value, $h->tr($status->label())) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= $h->url('web.projects') ?>"><?= $h->t('Back to projects') ?></a>
        <?php if ($canManage && $can('projects.update') && !$status->isTerminal()): ?>
            <a class="btn" href="<?= $h->url('web.projects.edit', ['id' => $project->id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
            <?php foreach ($transitions as $t): ?>
                <?php if ($status === ProjectStatus::Draft && $t === ProjectStatus::Active && !$can('projects.approve')) {
                    continue;
                } ?>
                <form method="post" action="<?= $h->url('web.projects.status', ['id' => $project->id]) ?>">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <input type="hidden" name="version" value="<?= $project->version() ?>">
                    <input type="hidden" name="status" value="<?= $h->e($t->value) ?>">
                    <button class="btn" type="submit"><?= $h->t($t->label()) ?></button>
                </form>
            <?php endforeach ?>
        <?php endif ?>
    </div>
</div>
<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Description') ?></dt><dd><?= $project->description() === '' ? '—' : nl2br($h->e($project->description())) ?></dd>
                <dt><?= $h->t('Priority') ?></dt><dd><?= $h->t(ucfirst($project->priority()->value)) ?></dd>
                <dt><?= $h->t('Start date') ?></dt><dd><?= $h->e($project->startsOn() ?? '—') ?></dd>
                <dt><?= $h->t('Due date') ?></dt><dd><?= $h->e($project->dueOn() ?? '—') ?></dd>
                <dt><?= $h->t('Tags') ?></dt><dd><?php foreach ($project->tags() as $t): ?><span class="tag"><?= $h->e($t) ?></span><?php endforeach ?><?= $project->tags() === [] ? '—' : '' ?></dd>
            </dl>
        </div>
        <div class="card__head"><h2><?= $h->t('Activity') ?></h2></div>
        <?php if ($activity['items'] === []): ?>
            <div class="empty"><?= $h->t('No activity yet.') ?></div>
        <?php else: ?>
            <ul class="timeline card__body">
                <?php foreach ($activity['items'] as $a): ?>
                    <li><div class="row row--between"><span><?= $h->e($a['summary']) ?></span><span class="small muted"><?= $h->ago($a['occurred_at']) ?></span></div></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Team') ?></h2></div>
        <ul class="card__body project-members">
            <?php foreach ($members as $m): ?>
                <li><?= $h->e($m['full_name'] ?: $m['username']) ?> <span class="tag"><?= $h->e($m['role']) ?></span></li>
            <?php endforeach ?>
        </ul>
    </div>
</div>
