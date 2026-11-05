<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string $active
 */
$tabs = [
    'channels' => ['admin.notifications', 'Channels', 'notifications.view'],
    'templates' => ['admin.notifications.templates', 'Templates', 'notifications.view'],
    'deliveries' => ['admin.notifications.deliveries', 'Deliveries', 'notifications.view'],
    'broadcast' => ['admin.notifications.broadcast', 'Broadcast', 'notifications.send'],
];
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Messaging & Notifications') ?></h1>
    <p class="page-head__sub"><?= $h->t('In-app, e-mail, Telegram, SMS and Web Push delivery through the queue with retries and failure tracking.') ?></p></div></div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($tabs as $key => [$route, $label, $perm]): if (!$can($perm)) { continue; } ?>
        <a href="<?= $h->url($route) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
