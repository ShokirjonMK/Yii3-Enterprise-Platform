<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $k8s
 * @var string $csrf
 * @var callable(string):bool $can
 */

$err = static fn(string $part) => $k8s['errors'][$part] ?? null;
?>
<div class="card__head"><h2><?= $h->t('Kubernetes') ?></h2>
    <?php if ($k8s['configured']): ?><span class="small muted mono"><?= $h->e($k8s['api']) ?> · ns/<?= $h->e($k8s['namespace']) ?><?= $k8s['version'] ? ' · ' . $h->e($k8s['version']['gitVersion']) : '' ?></span><?php endif ?>
</div>
<?php if (!$k8s['configured']): ?>
    <div class="empty" data-k8s="not-configured"><?= $h->icon('server') ?>
        <p><strong><?= $h->t('Not configured') ?></strong></p>
        <p class="small"><?= $h->t('Set ops.kubernetes_api, ops.kubernetes_token and ops.kubernetes_namespace in System Settings → Operations (or K8S_API_URL / K8S_TOKEN / K8S_NAMESPACE). The service account needs get/list on deployments, pods, HPAs and patch on deployments/scale.') ?></p>
    </div>
<?php else: ?>
    <?php if ($err('version')): ?><div class="card__body"><div class="alert alert--error"><?= $h->e($err('version')) ?></div></div><?php endif ?>

    <div class="card__body"><h3><?= $h->t('Deployments') ?></h3></div>
    <?php if ($err('deployments')): ?><div class="card__body"><div class="alert alert--warning"><?= $h->e($err('deployments')) ?></div></div>
    <?php elseif ($k8s['deployments'] !== null): ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th><?= $h->t('Name') ?></th><th class="num"><?= $h->t('Ready') ?></th><th class="num"><?= $h->t('Updated') ?></th><th><?= $h->t('Image') ?></th><th><?= $h->t('Age') ?></th><?php if ($can('infra.manage')): ?><th><?= $h->t('Scale') ?></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($k8s['deployments'] as $d): ?>
                <tr>
                    <td class="mono"><?= $h->e($d['name']) ?><?php foreach ($d['conditions'] as $c): ?><br><span class="small muted"><?= $h->e($c) ?></span><?php endforeach ?></td>
                    <td class="num"><?= $h->badge($d['ready'] >= $d['replicas'] ? 'healthy' : 'degraded', $d['ready'] . '/' . $d['replicas']) ?></td>
                    <td class="num"><?= (int) $d['updated'] ?></td>
                    <td class="small mono"><?= $h->e(implode(', ', $d['images'])) ?></td>
                    <td class="small"><?= $h->e($d['age'] ?? '—') ?></td>
                    <?php if ($can('infra.manage')): ?>
                    <td>
                        <form class="row ops-inline" method="post" action="<?= $h->url('admin.infrastructure.scale') ?>" data-confirm="<?= $h->t('Scale this deployment? The change is applied immediately and audited.') ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <input type="hidden" name="deployment" value="<?= $h->e($d['name']) ?>">
                            <input class="ops-num" type="number" name="replicas" min="0" max="100" value="<?= (int) $d['replicas'] ?>" aria-label="<?= $h->t('Replicas') ?>">
                            <input type="text" name="reason" required maxlength="500" placeholder="<?= $h->t('Reason') ?>" aria-label="<?= $h->t('Reason') ?>">
                            <button class="btn btn--sm" type="submit"><?= $h->t('Apply') ?></button>
                        </form>
                    </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>

    <div class="card__body"><h3><?= $h->t('Pods') ?></h3></div>
    <?php if ($err('pods')): ?><div class="card__body"><div class="alert alert--warning"><?= $h->e($err('pods')) ?></div></div>
    <?php elseif ($k8s['pods'] !== null): ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th><?= $h->t('Name') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Ready') ?></th><th class="num"><?= $h->t('Restarts') ?></th><th><?= $h->t('Node') ?></th><th><?= $h->t('IP') ?></th><th><?= $h->t('Age') ?></th></tr></thead>
            <tbody>
            <?php foreach ($k8s['pods'] as $p): ?>
                <tr>
                    <td class="mono small"><?= $h->e($p['name']) ?></td>
                    <td><?= $h->badge(match ($p['phase']) {
                        'Running', 'Succeeded' => 'healthy', 'Pending' => 'pending', default => 'failed'
                    }, $p['phase']) ?></td>
                    <td><?= $h->e($p['ready']) ?></td>
                    <td class="num"><?= (int) $p['restarts'] ?></td>
                    <td class="small"><?= $h->e($p['node']) ?></td>
                    <td class="small mono"><?= $h->e($p['ip']) ?></td>
                    <td class="small"><?= $h->e($p['age'] ?? '—') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>

    <div class="card__body grid grid--2">
        <div>
            <h3><?= $h->t('Nodes') ?></h3>
            <?php if ($err('nodes')): ?><div class="alert alert--warning small"><?= $h->e($err('nodes')) ?></div>
            <?php elseif ($k8s['nodes'] !== null): ?>
                <table class="table table--compact"><tbody>
                <?php foreach ($k8s['nodes'] as $n): ?>
                    <tr><td><span class="dot dot--<?= $n['ready'] ? 'ok' : 'bad' ?>"></span> <span class="mono small"><?= $h->e($n['name']) ?></span><br><span class="small muted"><?= $h->e(implode(', ', $n['roles']) ?: 'worker') ?> · <?= $h->e($n['version']) ?><?= $n['unschedulable'] ? ' · cordoned' : '' ?></span></td>
                        <td class="small right"><?= $h->e($n['cpu']) ?> CPU<br><?= $h->e($n['memory']) ?></td></tr>
                <?php endforeach ?>
                </tbody></table>
            <?php endif ?>
        </div>
        <div>
            <h3><?= $h->t('Horizontal pod autoscalers') ?></h3>
            <?php if ($err('hpas')): ?><div class="alert alert--warning small"><?= $h->e($err('hpas')) ?></div>
            <?php elseif ($k8s['hpas'] === []): ?><p class="muted small"><?= $h->t('No HPA in this namespace.') ?></p>
            <?php elseif ($k8s['hpas'] !== null): ?>
                <table class="table table--compact"><tbody>
                <?php foreach ($k8s['hpas'] as $a): ?>
                    <tr><td class="mono small"><?= $h->e($a['name']) ?><br><span class="muted"><?= $h->e($a['target']) ?></span></td>
                        <td class="small right"><?= (int) $a['current'] ?> → <?= (int) $a['desired'] ?> (<?= (int) $a['min'] ?>–<?= (int) $a['max'] ?>)<br><?= $h->e(implode('; ', $a['metrics'])) ?></td></tr>
                <?php endforeach ?>
                </tbody></table>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>
