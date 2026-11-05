<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $blocking
 */
?>
<div class="table-wrap"><table class="table table--compact">
    <thead><tr><th><?= $h->t('Blocked PID') ?></th><th class="num"><?= $h->t('Waiting') ?></th><th><?= $h->t('Blocked query') ?></th><th><?= $h->t('Blocking PID') ?></th><th><?= $h->t('Blocking state') ?></th><th><?= $h->t('Blocking query') ?></th></tr></thead>
    <tbody>
    <?php foreach ($blocking as $b): ?>
        <tr>
            <td class="num"><?= (int) $b['blocked_pid'] ?><div class="small muted"><?= $h->e($b['blocked_user']) ?></div></td>
            <td class="num"><?= $h->e($b['waiting_seconds']) ?> s</td>
            <td><code class="runtime-sql"><?= $h->e($b['blocked_query']) ?></code></td>
            <td class="num"><?= (int) $b['blocking_pid'] ?><div class="small muted"><?= $h->e($b['blocking_user']) ?></div></td>
            <td><?= $h->e($b['blocking_state']) ?></td>
            <td><code class="runtime-sql"><?= $h->e($b['blocking_query']) ?></code></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table></div>
