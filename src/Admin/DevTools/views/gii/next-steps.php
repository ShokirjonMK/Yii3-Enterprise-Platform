<?php

declare(strict_types=1);

/**
 * Admin · Developer tools · Enterprise Gii → Next steps: run migrate:up / rbac:sync / Kafka topics, run history.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var array $state
 * @var list<array<string,mixed>> $runs
 * @var string $module
 * @var array<string, list<string>> $errors
 */

$this->setTitle($h->tr('Next steps'));
$this->setParameter('breadcrumbs', [$h->tr('Developer tools') => $h->urlOrNull('admin.devtools'), $h->tr('Enterprise Gii') => $h->url('admin.devtools.gii'), $h->tr('Next steps')]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Next steps') ?></h1>
        <p class="page-head__sub"><?= $h->t('Apply migrations, synchronise roles and permissions and create Kafka topics from the panel — the same console commands, with live output.') ?></p>
    </div>
    <div class="page-head__actions"><a class="btn" href="<?= $h->url('admin.devtools.gii') ?>"><?= $h->icon('code') ?> <?= $h->t('Enterprise Gii') ?></a></div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert--error" role="alert"><?= $h->icon('alert') ?> <div><?php foreach ($errors as $msgs) {
        foreach ($msgs as $m) {
            echo $h->t($m), ' ';
        }
    } ?></div></div>
<?php endif ?>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Steps') ?></h2></div>
    <div class="card__body"><?= $this->render('./_steps-form', ['state' => $state, 'module' => $module, 'csrf' => $csrf, 'selected' => null]) ?></div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('History') ?></h2></div>
    <?php if ($runs === []): ?>
        <div class="card__body empty"><?= $h->t('No runs yet.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $h->t('Started') ?></th><th><?= $h->t('Module') ?></th><th><?= $h->t('Steps') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('By') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr>
                    <td class="nowrap"><?= $h->datetime($r['created_at']) ?></td>
                    <td class="mono"><?= $h->e($r['subject']) ?></td>
                    <td><?php foreach ($r['steps'] as $s): ?><?= $h->badge((string) $s['status'], (string) $s['key']) ?> <?php endforeach ?></td>
                    <td><?= $h->badge((string) $r['status']) ?> <span class="small muted"><?= $h->e($r['mode']) ?></span></td>
                    <td><?= $h->e($r['username'] ?? '—') ?></td>
                    <td><a class="btn btn--sm" href="<?= $h->url('admin.devtools.gii.runs.view', ['id' => $r['id']]) ?>"><?= $h->icon('eye') ?> <?= $h->t('Log') ?></a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>
