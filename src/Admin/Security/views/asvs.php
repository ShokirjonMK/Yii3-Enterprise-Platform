<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{chapters:list<array>, totals:array<string,int>, available:bool} $asvs
 */

$this->setTitle($h->tr('OWASP ASVS checklist'));
$this->setParameter('breadcrumbs', [$h->tr('Security Center') => $h->url('admin.security'), 'OWASP ASVS']);
$tone = ['implemented' => 'ok', 'partial' => 'warn', 'n/a' => 'muted', 'planned' => 'info'];
$t = $asvs['totals'];
$applicable = $t['implemented'] + $t['partial'] + $t['planned'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Security Center') ?></h1>
        <p class="page-head__sub"><?= $h->t('OWASP ASVS 4.0.3 Level 2 — source: docs/SECURITY_ASVS.md (reviewed with every security-relevant change).') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.security.asvs']) ?>

<?php if (!$asvs['available']): ?>
    <div class="card"><div class="empty"><?= $h->t('docs/SECURITY_ASVS.md was not found in this deployment.') ?></div></div>
<?php else: ?>
<div class="grid grid--4">
    <?php foreach ($tone as $status => $cls): ?>
        <div class="card kpi"><div class="kpi__label"><span class="badge badge--<?= $cls ?>"><?= $h->t($status) ?></span></div><div class="kpi__value"><?= (int) $t[$status] ?></div>
            <?php if ($status === 'implemented' && $applicable > 0): ?><div class="kpi__meta"><?= $h->t('{p}% of applicable requirements', ['p' => (int) round($t['implemented'] / $applicable * 100)]) ?></div><?php endif ?></div>
    <?php endforeach ?>
</div>
<?php foreach ($asvs['chapters'] as $ch): ?>
    <div class="card mt">
        <div class="card__head"><h2><?= $h->e($ch['code'] . ' · ' . $ch['title']) ?></h2><span class="small muted"><?= count($ch['items']) ?></span></div>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th>#</th><th><?= $h->t('Requirement') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Evidence') ?></th></tr></thead>
            <tbody>
            <?php foreach ($ch['items'] as $i): ?>
                <tr><td class="mono nowrap"><?= $h->e($i['id']) ?></td><td class="small"><?= $h->e($i['requirement']) ?></td>
                    <td><span class="badge badge--<?= $tone[$i['status']] ?>"><?= $h->t($i['status']) ?></span></td>
                    <td class="small mono muted"><?= $h->e($i['evidence']) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    </div>
<?php endforeach ?>
<?php endif ?>
