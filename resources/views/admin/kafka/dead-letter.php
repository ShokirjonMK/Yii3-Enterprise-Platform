<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array<string,mixed> $dl
 * @var bool $enabled
 */

$this->setTitle($h->tr('Dead letter #{id}', ['id' => $dl['id']]));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Dead letters') => $h->url('admin.kafka.dlq'), '#' . $dl['id']]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Dead letter #{id}', ['id' => $dl['id']]) ?> <?= $dl['replayed_at'] ? $h->badge('done', $h->tr('Replayed')) : $h->badge('failed', $h->tr('Not replayed')) ?></h1>
        <p class="page-head__sub mono"><?= $h->e($dl['topic']) ?> · <?= $h->e($dl['consumer_group']) ?></p>
    </div>
    <?php if ($can('kafka.manage')): ?>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('admin.kafka.dlq.replay', ['id' => $dl['id']]) ?>" data-confirm="<?= $h->t('Re-publish this message to {t}? Consumers are idempotent (inbox), but side effects of non-idempotent handlers may repeat. Requires re-authentication.', ['t' => $dl['topic']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn btn--primary" type="submit"<?= $enabled ? '' : ' disabled' ?>><?= $h->icon('refresh') ?> <?= $h->t($dl['replayed_at'] ? 'Replay again' : 'Replay') ?></button>
        </form>
    </div>
    <?php endif ?>
</div>
<?php if (!$enabled): ?><div class="alert alert--info"><?= $h->t('Kafka is not configured — replay is unavailable.') ?></div><?php endif ?>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('Error') ?></h2></div><div class="card__body"><pre><?= $h->e($dl['error']) ?></pre></div></div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Payload') ?></h2><span class="small muted"><?= $h->t('secrets redacted') ?></span></div><div class="card__body"><pre><?= is_array($dl['payload_view']) ? $h->json($dl['payload_view']) : $h->e($dl['payload_view']) ?></pre></div></div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Headers') ?></h2></div><div class="card__body"><pre><?= $h->json($dl['headers_view']) ?></pre></div></div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body"><dl class="dl runtime-dl">
            <dt><?= $h->t('Partition') ?></dt><dd><?= (int) $dl['partition'] ?></dd>
            <dt><?= $h->t('Offset') ?></dt><dd><?= (int) $dl['offset'] ?></dd>
            <dt><?= $h->t('Key') ?></dt><dd class="mono small"><?= $h->e($dl['message_key'] ?? '—') ?></dd>
            <dt><?= $h->t('Message id') ?></dt><dd class="mono small"><?= $h->e($dl['message_id'] ?? '—') ?></dd>
            <dt><?= $h->t('Attempts') ?></dt><dd><?= (int) $dl['attempts'] ?></dd>
            <dt><?= $h->t('Failed') ?></dt><dd><?= $h->datetime($dl['failed_at'], 'Y-m-d H:i:s') ?></dd>
            <dt><?= $h->t('Replayed') ?></dt><dd><?= $h->datetime($dl['replayed_at'], 'Y-m-d H:i:s') ?></dd>
            <dt><?= $h->t('Replayed by') ?></dt><dd class="mono small"><?= $h->e($dl['replayed_by'] ?? '—') ?></dd>
        </dl></div>
    </div>
</div>
