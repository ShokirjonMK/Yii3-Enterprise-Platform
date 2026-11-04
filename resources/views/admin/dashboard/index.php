<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,int> $kpis
 * @var list<array<string,mixed>> $audit
 * @var list<array<string,mixed>> $logins
 * @var callable(string):bool $can
 * @var App\Platform\Identity\Domain\Identity $identity
 */

$this->setTitle($h->tr('Dashboard'));

// Sparkline of failed logins (inline SVG path, no inline styles)
$points = array_map(static fn($r) => (int) $r['fail'] + (int) $r['ok'], $logins);
$max = max([1, ...$points]);
$n = max(1, count($points) - 1);
$path = '';
foreach ($points as $i => $v) {
    $x = round($i / $n * 300, 1);
    $y = round(46 - ($v / $max) * 42, 1);
    $path .= ($i === 0 ? 'M' : 'L') . "$x $y ";
}
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Platform Control Center') ?></h1>
        <p class="page-head__sub"><?= $h->t('Welcome, {name}', ['name' => $identity->user->displayName()]) ?></p>
    </div>
    <div class="page-head__actions">
        <button class="btn" hx-get="<?= $h->url('admin.dashboard.kpis') ?>" hx-target="#kpis" hx-swap="outerHTML"><?= $h->icon('refresh') ?> <?= $h->t('Refresh') ?></button>
    </div>
</div>

<?= $this->render('./_kpis', ['kpis' => $kpis]) ?>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Recent activity') ?></h2><?php if ($can('audit.view') && $h->urlOrNull('admin.audit')): ?><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.audit') ?>"><?= $h->t('Audit log') ?></a><?php endif ?></div>
            <?php if ($audit === []): ?>
                <div class="empty"><?= $h->t('No activity yet.') ?></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Time') ?></th><th><?= $h->t('Action') ?></th><th><?= $h->t('Actor') ?></th><th><?= $h->t('Entity') ?></th><th><?= $h->t('Result') ?></th></tr></thead>
                <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td class="nowrap small"><?= $h->ago($a['occurred_at']) ?></td>
                        <td><code><?= $h->e($a['action']) ?></code></td>
                        <td><?= $h->e($a['actor_name'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></td>
                        <td class="small"><?= $h->e(trim(($a['entity_type'] ?? '') . ' ' . substr((string) ($a['entity_id'] ?? ''), 0, 12))) ?></td>
                        <td><?= $h->badge($a['result']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Sign-in activity (24 h)') ?></h2><span class="small muted"><?= $h->t('Failed: {n}', ['n' => $kpis['login_failures'] ?? 0]) ?></span></div>
            <div class="card__body">
                <?php if ($points === []): ?>
                    <p class="muted"><?= $h->t('No sign-ins in the last 24 hours.') ?></p>
                <?php else: ?>
                    <svg class="sparkline" viewBox="0 0 300 48" preserveAspectRatio="none" role="img" aria-label="<?= $h->t('Sign-ins per hour') ?>"><path d="<?= $h->e(trim($path)) ?>"/></svg>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('System health') ?></h2></div>
        <div id="health" hx-get="<?= $h->url('admin.dashboard.health') ?>" hx-trigger="load, every 30s" hx-swap="innerHTML">
            <div class="card__body muted"><?= $h->t('Checking components…') ?></div>
        </div>
    </div>
</div>
