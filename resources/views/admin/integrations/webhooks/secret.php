<?php

declare(strict_types=1);

/**
 * Shown exactly once after creation or rotation. Not cached, not stored in the session.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $row
 * @var string $secret
 * @var bool $rotated
 */
$this->setTitle($h->tr('Webhook signing secret'));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Inbound webhooks') => $h->url('admin.integrations.webhooks'), $row['code']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $rotated ? $h->t('Secret rotated') : $h->t('Webhook endpoint created') ?></h1></div></div>
<div class="card">
    <div class="card__body">
        <div class="alert alert--warning"><?= $h->t('Copy the signing secret now. It is shown only once and cannot be retrieved later — rotate it if it is lost.') ?></div>
        <dl class="dl">
            <dt><?= $h->t('Endpoint') ?></dt><dd class="mono">POST /webhooks/<?= $h->e($row['code']) ?></dd>
            <dt><?= $h->t('Signing secret') ?></dt><dd><code id="webhook-secret" data-secret><?= $h->e($secret) ?></code> <button type="button" class="btn btn--sm" data-copy="<?= $h->e($secret) ?>"><?= $h->icon('file') ?> <?= $h->t('Copy') ?></button></dd>
            <dt><?= $h->t('Signature') ?></dt><dd class="mono small"><?= $h->e($row['signature_header']) ?>: sha256=HMAC_SHA256(secret, <?= $h->e($row['timestamp_header']) ?> + "." + raw_body)</dd>
        </dl>
    </div>
    <div class="card__foot"><a class="btn btn--primary" href="<?= $h->url('admin.integrations.webhooks.show', ['id' => $row['id']]) ?>"><?= $h->t('I have stored the secret') ?></a></div>
</div>
