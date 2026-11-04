<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,int> $usage
 * @var string $active
 */

$tiles = [
    ['api', 'API clients', $usage['clients_enabled'] ?? 0, $h->tr('{n} total · {u} used in 24 h', ['n' => $usage['clients_total'] ?? 0, 'u' => $usage['clients_used_24h'] ?? 0])],
    ['key', 'Personal tokens', $usage['personal_active'] ?? 0, $h->tr('active · {n} used in 24 h', ['n' => $usage['personal_used_24h'] ?? 0])],
    ['activity', 'Personal tokens used (7 d)', $usage['personal_used_7d'] ?? 0, $h->tr('distinct tokens')],
    ['bolt', 'Access tokens (24 h)', $usage['access_issued_24h'] ?? 0, $h->tr('issued · {n} used', ['n' => $usage['access_used_24h'] ?? 0])],
];
?>
<div class="grid grid--4 mb">
    <?php foreach ($tiles as [$icon, $label, $value, $meta]): ?>
        <div class="card kpi"><div class="kpi__label"><?= $h->icon($icon) ?> <?= $h->t($label) ?></div><div class="kpi__value"><?= (int) $value ?></div><div class="kpi__meta"><?= $h->e($meta) ?></div></div>
    <?php endforeach ?>
</div>
<nav class="tabs" aria-label="<?= $h->t('Section') ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('admin.api') ?>"<?= $active === 'clients' ? ' aria-current="page"' : '' ?>><?= $h->t('API clients') ?></a>
    <a href="<?= $h->url('admin.api.tokens') ?>"<?= $active === 'tokens' ? ' aria-current="page"' : '' ?>><?= $h->t('Personal tokens') ?></a>
</nav>
