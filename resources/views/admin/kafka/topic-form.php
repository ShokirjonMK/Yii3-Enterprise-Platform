<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $csrf
 * @var array<string,mixed>|null $topic
 * @var array<string,mixed> $values
 * @var array<string, list<string>> $errors
 */

$isNew = $topic === null;
$title = $isNew ? $h->tr('Register topic') : $h->tr('Edit topic {t}', ['t' => $topic['name']]);
$this->setTitle($title);
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $isNew ? $h->tr('New topic') : (string) $topic['name']]);
$action = $isNew ? $h->url('admin.kafka.topics.store') : $h->url('admin.kafka.topics.update', ['name' => $topic['name']]);
$err = static function (string $f) use ($errors, $h): string {
    return implode('', array_map(static fn($m) => '<div class="field__error">' . $h->t($m) . '</div>', $errors[$f] ?? []));
};
$inv = static fn(string $f): string => isset($errors[$f]) ? ' field--invalid' : '';
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1>
    <p class="page-head__sub"><?= $h->t('The registry is the desired state; apply it with the provisioning script (kafka-topics.sh --if-not-exists).') ?></p></div></div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka']) ?>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>
<form class="card" method="post" action="<?= $h->e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form form--2">
        <div class="field<?= $inv('name') ?>">
            <label for="name"><?= $h->t('Topic name') ?></label>
            <input type="text" id="name" name="name" class="mono" value="<?= $h->e($values['name'] ?? '') ?>" <?= $isNew ? 'required' : 'readonly' ?>>
            <?= $err('name') ?>
        </div>
        <div class="field<?= $inv('partitions') ?>">
            <label for="partitions"><?= $h->t('Partitions') ?></label>
            <input type="number" id="partitions" name="partitions" min="1" max="1000" value="<?= $h->e($values['partitions'] ?? 3) ?>" required>
            <?= $err('partitions') ?>
            <div class="field__hint"><?= $h->t('Partitions can only be increased.') ?></div>
        </div>
        <div class="field<?= $inv('replication_factor') ?>">
            <label for="replication_factor"><?= $h->t('Replication factor') ?></label>
            <input type="number" id="replication_factor" name="replication_factor" min="1" max="7" value="<?= $h->e($values['replication_factor'] ?? 1) ?>" required>
            <?= $err('replication_factor') ?>
            <div class="field__hint"><?= $h->t('Use 3 in production (min.insync.replicas=2).') ?></div>
        </div>
        <div class="field<?= $inv('retention_hours') ?>">
            <label for="retention_hours"><?= $h->t('Retention (hours, -1 = unlimited)') ?></label>
            <input type="number" id="retention_hours" name="retention_hours" min="-1" max="87600" value="<?= $h->e($values['retention_hours'] ?? 168) ?>" required>
            <?= $err('retention_hours') ?>
        </div>
        <div class="field<?= $inv('cleanup_policy') ?>">
            <label for="cleanup_policy"><?= $h->t('Cleanup policy') ?></label>
            <select id="cleanup_policy" name="cleanup_policy">
                <?php foreach (['delete', 'compact', 'compact,delete'] as $p): ?><option value="<?= $h->e($p) ?>"<?= ($values['cleanup_policy'] ?? 'delete') === $p ? ' selected' : '' ?>><?= $h->e($p) ?></option><?php endforeach ?>
            </select>
            <?= $err('cleanup_policy') ?>
        </div>
        <div class="field span-2">
            <label for="description"><?= $h->t('Description') ?></label>
            <textarea id="description" name="description" maxlength="1000"><?= $h->e($values['description'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="card__foot">
        <a class="btn btn--ghost" href="<?= $h->url('admin.kafka') ?>"><?= $h->t('Cancel') ?></a>
        <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
    </div>
</form>
