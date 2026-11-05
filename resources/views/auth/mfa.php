<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string|null $error
 * @var int|null $left
 * @var array<string,string> $routes
 */

$this->setTitle($h->tr('Two-factor verification'));
$params = get_defined_vars();
unset($params['this']);
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Two-factor verification') ?></h1>
        <?php if (!empty($error)): ?>
            <div class="alert alert--error" role="alert"><?= $h->t($error) ?><?php if (isset($left)): ?> <?= $h->t('Attempts left: {n}', ['n' => $left]) ?><?php endif ?></div>
        <?php endif ?>
        <?= $this->render('./_second-factor', $params) ?>
        <p class="mt"><a class="btn btn--ghost btn--sm" href="<?= $h->url($routes['cancel']) ?>"><?= $h->t('Cancel') ?></a></p>
    </div>
</div>
