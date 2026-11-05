<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $i
 * @var list<array<string,mixed>> $timeline
 * @var list<string> $transitions
 * @var list<array<string,mixed>> $oncall
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Incident #{n}', ['n' => $i['number']]));
$this->setParameter('breadcrumbs', [$h->tr('Incident Management') => $h->url('admin.incidents'), '#' . $i['number']]);
$manage = $can('incidents.manage');
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$icons = ['opened' => 'alert', 'acknowledged' => 'check', 'status' => 'flow', 'note' => 'edit', 'commander' => 'user', 'postmortem' => 'file', 'notified' => 'bell'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1>#<?= (int) $i['number'] ?> · <?= $h->e($i['title']) ?></h1>
        <p class="page-head__sub"><?= $h->badge($i['severity'], strtoupper((string) $i['severity'])) ?> <?= $h->badge($i['status']) ?> · <span class="mono"><?= $h->e($i['service']) ?></span> · <?= $h->t('opened {ago}', ['ago' => $h->ago($i['opened_at'])]) ?><?= $i['fingerprint'] ? ' · <span class="tag">' . $h->e($i['fingerprint']) . '</span>' : '' ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($manage && $i['acknowledged_at'] === null && !in_array($i['status'], ['resolved', 'closed'], true)): ?>
            <form method="post" action="<?= $h->url('admin.incidents.ack', ['id' => $i['id']]) ?>"><?= $csrfInput ?><button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Acknowledge') ?></button></form>
        <?php endif ?>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <?php if (trim((string) $i['description']) !== ''): ?>
        <div class="card"><div class="card__head"><h2><?= $h->t('Description') ?></h2></div><div class="card__body ops-prewrap"><?= $h->e($i['description']) ?></div></div>
        <?php endif ?>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('Timeline') ?></h2></div>
            <div class="card__body">
                <ol class="timeline" id="incident-timeline">
                    <?php foreach ($timeline as $e): ?>
                        <li data-event="<?= $h->e($e['type']) ?>"><div class="small muted"><?= $h->icon($icons[$e['type']] ?? 'info', 'icon--sm') ?> <?= $h->datetime($e['created_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($e['actor'] ?? 'system') ?></div>
                            <div class="ops-prewrap"><?= $h->e($e['body']) ?></div></li>
                    <?php endforeach ?>
                </ol>
                <?php if ($manage && $i['status'] !== 'closed'): ?>
                <form class="form mt" method="post" action="<?= $h->url('admin.incidents.note', ['id' => $i['id']]) ?>">
                    <?= $csrfInput ?>
                    <div class="field"><label for="n-b"><?= $h->t('Add an update') ?></label><textarea id="n-b" name="body" required maxlength="10000"></textarea></div>
                    <button class="btn" type="submit"><?= $h->icon('edit') ?> <?= $h->t('Post update') ?></button>
                </form>
                <?php endif ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('Postmortem') ?></h2><span class="small muted"><?= $h->t('required to close sev1/sev2') ?></span></div>
            <div class="card__body">
            <?php if ($manage): ?>
                <form class="form" method="post" action="<?= $h->url('admin.incidents.postmortem', ['id' => $i['id']]) ?>">
                    <?= $csrfInput ?>
                    <textarea class="code" name="postmortem" aria-label="<?= $h->t('Postmortem') ?>" placeholder="<?= $h->t("Summary\nImpact\nTimeline\nRoot cause\nWhat went well / badly\nAction items (owner, due date)") ?>"><?= $h->e($i['postmortem'] ?? '') ?></textarea>
                    <button class="btn" type="submit"><?= $h->icon('file') ?> <?= $h->t('Save postmortem') ?></button>
                </form>
            <?php elseif ($i['postmortem']): ?>
                <div class="ops-prewrap"><?= $h->e($i['postmortem']) ?></div>
            <?php else: ?><p class="muted"><?= $h->t('No postmortem yet.') ?></p><?php endif ?>
            </div>
        </div>
    </div>

    <div class="stack">
        <?php if ($manage && $transitions !== []): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Change status') ?></h2></div>
            <div class="card__body">
                <form class="form" method="post" action="<?= $h->url('admin.incidents.transition', ['id' => $i['id']]) ?>">
                    <?= $csrfInput ?>
                    <div class="field"><label for="t-s"><?= $h->t('New status') ?></label>
                        <select id="t-s" name="status"><?php foreach ($transitions as $t): ?><option value="<?= $h->e($t) ?>"><?= $h->t($t) ?></option><?php endforeach ?></select></div>
                    <div class="field"><label for="t-n"><?= $h->t('Note') ?></label><input id="t-n" type="text" name="note" maxlength="500"></div>
                    <button class="btn btn--primary" type="submit"><?= $h->t('Update status') ?></button>
                </form>
            </div>
        </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
            <div class="card__body">
                <dl class="dl ops-dl-tight">
                    <dt><?= $h->t('Commander') ?></dt><dd><?= $h->e($i['commander'] ?? '—') ?></dd>
                    <dt><?= $h->t('Opened by') ?></dt><dd><?= $h->e($i['opener'] ?? $i['source']) ?></dd>
                    <dt><?= $h->t('Opened') ?></dt><dd><?= $h->datetime($i['opened_at'], 'Y-m-d H:i:s') ?></dd>
                    <dt><?= $h->t('Acknowledged') ?></dt><dd><?= $h->datetime($i['acknowledged_at'], 'Y-m-d H:i:s') ?></dd>
                    <dt><?= $h->t('Resolved') ?></dt><dd><?= $h->datetime($i['resolved_at'], 'Y-m-d H:i:s') ?></dd>
                    <dt><?= $h->t('Closed') ?></dt><dd><?= $h->datetime($i['closed_at'], 'Y-m-d H:i:s') ?></dd>
                </dl>
                <?php if ($manage && $i['status'] !== 'closed'): ?>
                <form class="form mt" method="post" action="<?= $h->url('admin.incidents.commander', ['id' => $i['id']]) ?>">
                    <?= $csrfInput ?>
                    <div class="field"><label for="c-u"><?= $h->t('Assign commander') ?></label>
                        <select id="c-u" name="commander_id"><option value=""><?= $h->t('— nobody —') ?></option>
                            <?php foreach ($oncall as $u): ?><option value="<?= $h->e($u['id']) ?>"<?= $u['id'] === $i['commander_id'] ? ' selected' : '' ?>><?= $h->e(($u['full_name'] ?: $u['username']) . ' (' . $u['username'] . ')') ?></option><?php endforeach ?></select></div>
                    <button class="btn btn--sm" type="submit"><?= $h->t('Assign') ?></button>
                </form>
                <?php endif ?>
            </div>
        </div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Runbooks') ?></h2></div>
            <ul class="card__body small"><li><span class="mono">docs/OPERATIONS.md</span> — <?= $h->t('incident response & on-call') ?></li><li><span class="mono">docs/runbooks/</span> — <?= $h->t('component runbooks') ?></li></ul></div>
    </div>
</div>
