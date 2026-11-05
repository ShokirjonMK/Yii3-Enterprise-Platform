<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{topic:string, key:string, payload:string, batch:bool} $values
 * @var array<string, list<string>> $errors
 * @var list<string>|null $ids
 * @var list<string> $topics
 * @var string $prefix
 * @var bool $enabled
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Publish a message'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Publish')]);
$manage = $can('kafka.manage');
$err = static fn(string $f) => $errors[$f] ?? [];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Publish a message') ?></h1>
        <p class="page-head__sub"><?= $h->t('Sends JSON directly to a topic and waits for broker confirmation. Headers event_id, correlation_id, produced_at and source are added automatically. Same as ./yii kafka:publish.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.publish']) ?>
<?php if (!$enabled): ?><div class="alert alert--info"><?= $h->icon('info') ?> <div><strong><?= $h->t('Not configured') ?></strong> — <?= $h->t('Kafka is disabled (KAFKA_ENABLED=false).') ?></div></div><?php endif ?>

<?php if ($ids !== null): ?>
    <div class="alert alert--success"><?= $h->icon('check') ?> <div><strong><?= $h->t('{n} message(s) confirmed by Kafka', ['n' => count($ids)]) ?></strong> → <span class="mono"><?= $h->e($prefix . $values['topic']) ?></span>
        <div class="small mono"><?php foreach (array_slice($ids, 0, 10) as $id): ?>event_id=<?= $h->e($id) ?><br><?php endforeach ?></div></div></div>
<?php endif ?>

<form class="card" method="post" action="<?= $h->url('admin.kafka.publish.send') ?>" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form">
        <div class="field<?= $err('topic') ? ' field--invalid' : '' ?>">
            <label for="topic"><?= $h->t('Topic') ?></label>
            <input id="topic" name="topic" list="kafka-topics" required pattern="[A-Za-z0-9._-]{1,200}" value="<?= $h->e($values['topic']) ?>" placeholder="platform.notifications.inbound">
            <datalist id="kafka-topics"><?php foreach ($topics as $t): ?><option value="<?= $h->e($t) ?>"><?php endforeach ?></datalist>
            <?php if ($prefix !== ''): ?><div class="field__hint"><?= $h->t('Prefix “{p}” is added automatically.', ['p' => $prefix]) ?></div><?php endif ?>
            <?php foreach ($err('topic') as $e): ?><div class="field__error"><?= $h->e($e) ?></div><?php endforeach ?>
        </div>
        <div class="field">
            <label for="key"><?= $h->t('Key (optional)') ?></label>
            <input id="key" name="key" maxlength="200" value="<?= $h->e($values['key']) ?>">
            <div class="field__hint"><?= $h->t('Messages with the same key go to the same partition (ordering). Default: the event id.') ?></div>
        </div>
        <div class="field<?= $err('payload') ? ' field--invalid' : '' ?>">
            <label for="payload"><?= $h->t('JSON payload') ?></label>
            <textarea id="payload" name="payload" rows="10" class="mono" required><?= $h->e($values['payload']) ?></textarea>
            <?php foreach ($err('payload') as $e): ?><div class="field__error"><?= $h->e($e) ?></div><?php endforeach ?>
        </div>
        <label class="check"><input type="checkbox" name="batch" value="1"<?= $values['batch'] ? ' checked' : '' ?>> <?= $h->t('Batch: the payload is a JSON array, every element is a separate message') ?></label>
    </div>
    <?php if ($manage): ?>
        <div class="card__foot"><button class="btn btn--primary" type="submit"<?= $enabled ? '' : ' disabled' ?>><?= $h->icon('play') ?> <?= $h->t('Publish') ?></button>
            <span class="small muted"><?= $h->t('Requires re-authentication; recorded in the audit log.') ?></span></div>
    <?php endif ?>
</form>
