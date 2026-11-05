<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{errors:list<string>, warnings:list<string>} $report
 */
$ok = $report['errors'] === [];
?>
<div id="validation-report" data-valid="<?= $ok ? '1' : '0' ?>">
    <?php if ($ok): ?><div class="alert alert--success"><?= $h->icon('check') ?> <?= $h->t('Definition is valid.') ?></div><?php endif ?>
    <?php foreach ($report['errors'] as $e): ?><div class="alert alert--error"><?= $h->icon('x') ?> <?= $h->e($e) ?></div><?php endforeach ?>
    <?php foreach ($report['warnings'] as $w): ?><div class="alert alert--warning"><?= $h->icon('alert') ?> <?= $h->e($w) ?></div><?php endforeach ?>
</div>
