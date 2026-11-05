<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $script
 * @var list<array<string,mixed>> $rows
 * @var int|null $rf
 */

$this->setTitle($h->tr('Topic provisioning'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Provisioning')]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Topic provisioning') ?></h1>
        <p class="page-head__sub"><?= $h->t('Same output as ./yii kafka:provision. Run it with Kafka\'s CLI (kafka-init job / Kubernetes Job) — the application never creates topics itself.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.kafka.provision.download', [], $rf === null ? [] : ['rf' => $rf]) ?>" download><?= $h->icon('download') ?> <?= $h->t('Download script') ?></a>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.provision']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.kafka.provision') ?>" hx-get="<?= $h->url('admin.kafka.provision') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <label for="rf" class="small"><?= $h->t('Override replication factor') ?></label>
        <select id="rf" name="rf" hx-trigger="change">
            <option value=""><?= $h->t('from registry') ?></option>
            <?php foreach ([1, 2, 3, 5] as $n): ?><option value="<?= $n ?>"<?= $rf === $n ? ' selected' : '' ?>><?= $n ?></option><?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->t('Render') ?></button>
    </form>
    <div class="card__body">
        <pre class="runtime-script"><?= $h->e($script) ?></pre>
        <p class="small muted"><?= $h->t('{n} topics in the registry.', ['n' => count($rows)]) ?> <?= $h->t('Strimzi users can map the registry to KafkaTopic resources instead.') ?></p>
    </div>
</div>
