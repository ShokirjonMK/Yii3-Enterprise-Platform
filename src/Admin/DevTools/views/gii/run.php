<?php

declare(strict_types=1);

/**
 * Admin · Developer tools · Enterprise Gii → Next steps → one run (live status and console output).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $run
 */

$this->setTitle($h->tr('Next steps') . ' · ' . $run['subject']);
$this->setParameter('breadcrumbs', [$h->tr('Developer tools') => $h->urlOrNull('admin.devtools'), $h->tr('Enterprise Gii') => $h->url('admin.devtools.gii'), $h->tr('Next steps') => $h->url('admin.devtools.gii.steps'), $h->tr('Run')]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Next steps') ?> · <span class="mono"><?= $h->e($run['subject']) ?></span></h1>
        <p class="page-head__sub mono small"><?= $h->e($run['id']) ?></p>
    </div>
    <div class="page-head__actions"><a class="btn" href="<?= $h->url('admin.devtools.gii.steps') ?>"><?= $h->icon('refresh') ?> <?= $h->t('Next steps') ?></a></div>
</div>
<?= $this->render('./_run-live', ['run' => $run]) ?>
