<?php

declare(strict_types=1);

/**
 * Cancel-query button (only rendered for application-owned client backends; the server re-checks).
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $csrf
 * @var int $pid
 */
?>
<form method="post" action="<?= $h->url('admin.postgres.cancel') ?>" data-confirm="<?= $h->t('Cancel the running query of backend {pid}? The session stays connected. Requires re-authentication.', ['pid' => $pid]) ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <input type="hidden" name="pid" value="<?= (int) $pid ?>">
    <button class="btn btn--sm btn--danger" type="submit"><?= $h->icon('x') ?> <?= $h->t('Cancel query') ?></button>
</form>
