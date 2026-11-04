<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var string $state up|down|not_configured
 * @var array|null $meta
 * @var string|null $error
 * @var list<array<string,mixed>> $drift
 * @var list<string> $unregistered
 * @var array{brokers:string,prefix:string,protocol:string,client:string} $config
 * @var array{pending:int,failed:int,published_24h:int,oldest_pending:?string} $outbox
 */

$this->setTitle($h->tr('Kafka Management'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management')]);
$manage = $can('kafka.manage');
$driftCount = count(array_filter($drift, static fn($r) => $r['drift']));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Kafka Management') ?></h1>
        <p class="page-head__sub"><?= $h->t('Live cluster metadata, topic registry vs. cluster drift, transactional outbox.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka']) ?>

<?php if ($state === 'not_configured'): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <div><strong><?= $h->t('Not configured') ?></strong> — <?= $h->t('Kafka is disabled (KAFKA_ENABLED=false). Events keep accumulating safely in the outbox.') ?></div></div>
<?php elseif ($state === 'down'): ?>
    <div class="alert alert--error"><?= $h->icon('alert') ?> <div><strong><?= $h->t('Kafka unreachable') ?></strong> — <span class="mono small"><?= $h->e($error) ?></span></div></div>
<?php endif ?>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('server') ?> <?= $h->t('Brokers') ?></div><div class="kpi__value"><?= $meta === null ? '—' : count($meta['brokers']) ?></div><div class="kpi__meta mono"><?= $h->e($config['brokers']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('stream') ?> <?= $h->t('Topics (live)') ?></div><div class="kpi__value"><?= $meta === null ? '—' : count($meta['topics']) ?></div><div class="kpi__meta"><?= $h->t('{n} registered', ['n' => count($drift)]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Drift') ?></div><div class="kpi__value"><?= $meta === null ? '—' : $driftCount ?></div><div class="kpi__meta"><?= $h->t('missing / under-partitioned') ?></div></div>
    <a class="card kpi" href="<?= $h->url('admin.kafka.outbox') ?>"><div class="kpi__label"><?= $h->icon('archive') ?> <?= $h->t('Outbox pending') ?></div><div class="kpi__value"><?= (int) $outbox['pending'] ?></div><div class="kpi__meta"><?= $h->t('{f} failed · {p} published (24 h)', ['f' => $outbox['failed'], 'p' => $outbox['published_24h']]) ?></div></a>
</div>

<div class="grid grid--sidebar mt">
    <div class="card">
        <div class="card__head">
            <h2><?= $h->t('Topic registry') ?></h2>
            <?php if ($manage): ?><a class="btn btn--sm btn--primary" href="<?= $h->url('admin.kafka.topics.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('Register topic') ?></a><?php endif ?>
        </div>
        <?php if ($drift === []): ?>
            <div class="empty"><?= $h->t('The registry is empty.') ?></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th><?= $h->t('Topic') ?></th><th class="num"><?= $h->t('Partitions') ?></th><th class="num"><?= $h->t('RF') ?></th><th><?= $h->t('Retention') ?></th><th><?= $h->t('Policy') ?></th><th><?= $h->t('Cluster') ?></th><?php if ($manage): ?><th></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($drift as $t): $ret = (int) $t['retention_ms']; ?>
                <tr>
                    <td><code><?= $h->e($t['name']) ?></code><?php if ($t['description'] !== ''): ?><div class="small muted"><?= $h->e($t['description']) ?></div><?php endif ?></td>
                    <td class="num"><?= (int) $t['partitions'] ?><?= $t['live_partitions'] !== null && (int) $t['live_partitions'] !== (int) $t['partitions'] ? ' <span class="small muted">(' . $h->t('live {n}', ['n' => $t['live_partitions']]) . ')</span>' : '' ?></td>
                    <td class="num"><?= (int) $t['replication_factor'] ?></td>
                    <td class="small"><?= $ret === -1 ? $h->t('unlimited') : $h->e(round($ret / 3_600_000, 1) . ' h') ?></td>
                    <td><span class="tag"><?= $h->e($t['cleanup_policy']) ?></span></td>
                    <td><?php if ($t['exists'] === null): ?><?= $h->badge('unknown', $h->tr('Unknown')) ?><?php elseif ($t['drift']): ?><?= $h->badge('failed', $h->tr($t['exists'] ? 'Under-partitioned' : 'Missing')) ?><?php else: ?><?= $h->badge('ok', $h->tr('In sync')) ?><?php endif ?></td>
                    <?php if ($manage): ?>
                    <td class="actions">
                        <div class="btn-group">
                            <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.kafka.topics.edit', ['name' => $t['name']]) ?>"><?= $h->icon('edit') ?></a>
                            <form method="post" action="<?= $h->url('admin.kafka.topics.delete', ['name' => $t['name']]) ?>" data-confirm="<?= $h->t('Remove {t} from the registry? The live topic and its data are NOT deleted.', ['t' => $t['name']]) ?>">
                                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                                <button class="btn btn--sm btn--danger" type="submit" aria-label="<?= $h->t('Unregister') ?>"><?= $h->icon('trash') ?></button>
                            </form>
                        </div>
                    </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
        <?php if ($unregistered !== []): ?>
            <div class="card__body small"><strong><?= $h->t('Live topics not in the registry:') ?></strong> <?php foreach ($unregistered as $u): ?><span class="tag"><?= $h->e($u) ?></span><?php endforeach ?></div>
        <?php endif ?>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Cluster') ?></h2><?= $h->badge($state === 'up' ? 'healthy' : $state, $h->tr($state === 'up' ? 'Up' : ($state === 'down' ? 'Down' : 'Not configured'))) ?></div>
            <div class="card__body">
                <dl class="dl runtime-dl">
                    <dt><?= $h->t('Bootstrap') ?></dt><dd class="mono small"><?= $h->e($config['brokers']) ?></dd>
                    <dt><?= $h->t('Security') ?></dt><dd><?= $h->e($config['protocol']) ?></dd>
                    <dt><?= $h->t('Client id') ?></dt><dd class="mono small"><?= $h->e($config['client']) ?></dd>
                    <dt><?= $h->t('Topic prefix') ?></dt><dd class="mono small"><?= $h->e($config['prefix'] === '' ? '—' : $config['prefix']) ?></dd>
                </dl>
            </div>
            <?php if ($meta !== null): ?>
            <table class="table table--compact"><thead><tr><th><?= $h->t('Broker id') ?></th><th><?= $h->t('Address') ?></th></tr></thead><tbody>
                <?php foreach ($meta['brokers'] as $b): ?><tr><td class="num"><?= (int) $b['id'] ?></td><td class="mono small"><?= $h->e($b['host']) ?></td></tr><?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
        <?php if ($meta !== null): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Live topics') ?></h2></div>
            <table class="table table--compact"><tbody>
                <?php foreach ($meta['topics'] as $name => $t): ?><tr><td class="mono small"><?= $h->e($name) ?></td><td class="num small"><?= (int) $t['partitions'] ?> p</td></tr><?php endforeach ?>
            </tbody></table>
        </div>
        <?php endif ?>
    </div>
</div>
