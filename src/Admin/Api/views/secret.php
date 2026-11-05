<?php

declare(strict_types=1);

/**
 * One-time display of an API client secret (only an Argon2id hash is stored).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $client
 * @var string $secret
 * @var string $reason created|rotated
 */

$this->setTitle($h->tr('Client credentials'));
$this->setParameter('breadcrumbs', [$h->tr('API Management') => $h->url('admin.api'), (string) $client['name'] => $h->url('admin.api.clients.view', ['id' => $client['id']]), $h->tr('Credentials')]);
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Client credentials') ?></h1>
    <p class="page-head__sub"><?= $reason === 'created' ? $h->t('API client {n} was created.', ['n' => $client['name']]) : $h->t('The secret of {n} was rotated. The previous secret no longer works.', ['n' => $client['name']]) ?></p>
</div></div>

<div class="card id-secret">
    <div class="card__body stack">
        <div class="alert alert--warning" role="alert"><?= $h->icon('alert') ?> <span><?= $h->t('Copy the client secret now. It is shown only once; the platform stores only an irreversible hash.') ?></span></div>
        <dl class="dl">
            <dt><?= $h->t('Client ID') ?></dt>
            <dd class="id-secret__value"><code class="mono"><?= $h->e($client['client_id']) ?></code> <button type="button" class="btn btn--sm" data-copy="<?= $h->e($client['client_id']) ?>"><?= $h->t('Copy') ?></button></dd>
            <dt><?= $h->t('Client secret') ?></dt>
            <dd class="id-secret__value"><code class="mono" data-client-secret><?= $h->e($secret) ?></code> <button type="button" class="btn btn--sm" data-copy="<?= $h->e($secret) ?>"><?= $h->t('Copy') ?></button></dd>
        </dl>
        <div><a class="btn btn--primary" href="<?= $h->url('admin.api.clients.view', ['id' => $client['id']]) ?>"><?= $h->t('Done') ?></a></div>
    </div>
</div>
