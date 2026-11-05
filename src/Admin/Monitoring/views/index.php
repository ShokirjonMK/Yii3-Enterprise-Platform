<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $o
 */

$this->setTitle($h->tr('Monitoring & Observability'));
$this->setParameter('breadcrumbs', [$h->tr('Monitoring & Observability')]);
$links = array_filter($o['links']);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Monitoring & Observability') ?></h1>
        <p class="page-head__sub"><?= $h->t('RED metrics, queues, consumer lag, data store exporters and active alerts.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--sm" href="<?= $h->url('admin.monitoring.logs') ?>"><?= $h->icon('file') ?> <?= $h->t('Application logs') ?></a>
        <?php foreach (['grafana' => 'Grafana', 'jaeger' => 'Jaeger', 'prometheus' => 'Prometheus', 'alertmanager' => 'Alertmanager'] as $k => $label): ?>
            <?php if (isset($links[$k])): ?><a class="btn btn--sm" href="<?= $h->e($links[$k]) ?>" target="_blank" rel="noopener noreferrer"><?= $h->icon('activity') ?> <?= $h->e($label) ?></a><?php endif ?>
        <?php endforeach ?>
        <?php if ($links === []): ?><span class="small muted"><?= $h->t('Grafana / Jaeger links: set GRAFANA_URL and JAEGER_URL') ?></span><?php endif ?>
    </div>
</div>
<?= $this->render('./_live', ['o' => $o]) ?>
