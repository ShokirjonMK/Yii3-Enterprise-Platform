<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array<string, array{topics:list<string>, handlers:array<string,string>}> $groups
 * @var array<string, array{paused:bool, paused_by:?string, paused_at:?string}> $states
 * @var array<string, list<array<string,mixed>>> $processes
 * @var bool $enabled
 */

use App\Admin\Action\Kafka\KafkaReadModel;

$this->setTitle($h->tr('Consumer groups'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Consumer groups')]);
$manage = $can('kafka.manage');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Consumer groups') ?></h1>
        <p class="page-head__sub"><?= $h->t('Groups from config/common/events.php. Pausing sets consumer_state.paused; running consumers pause their partitions within 5 s (no rebalance).') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.consumers']) ?>
<?php if (!$enabled): ?><div class="alert alert--info"><?= $h->icon('info') ?> <div><strong><?= $h->t('Not configured') ?></strong> — <?= $h->t('Kafka is disabled; lag cannot be measured.') ?></div></div><?php endif ?>

<?php foreach ($groups as $group => $def):
    $state = $states[$group] ?? ['paused' => false, 'paused_by' => null, 'paused_at' => null];
    $procs = $processes[$group] ?? [];
    $alive = count(array_filter($procs, static fn($p) => $p['alive'])); ?>
<div class="card mb">
    <div class="card__head">
        <h2 class="mono"><?= $h->e($group) ?></h2>
        <?= $state['paused'] ? $h->badge('paused', $h->tr('Paused')) : $h->badge('active', $h->tr('Active')) ?>
        <span class="small muted"><?= $h->t('{n} consumer process(es) alive', ['n' => $alive]) ?></span>
        <?php if ($manage): ?>
        <form method="post" action="<?= $h->url('admin.kafka.consumers.pause', ['group' => $group]) ?>"<?= $state['paused'] ? '' : ' data-confirm="' . $h->t('Pause consumer group {g}? Messages accumulate as lag until resumed.', ['g' => $group]) . '"' ?>>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <input type="hidden" name="paused" value="<?= $state['paused'] ? '0' : '1' ?>">
            <button class="btn btn--sm" type="submit"><?= $h->icon($state['paused'] ? 'play' : 'pause') ?> <?= $h->t($state['paused'] ? 'Resume' : 'Pause') ?></button>
        </form>
        <?php endif ?>
    </div>
    <div class="card__body">
        <dl class="dl runtime-dl">
            <dt><?= $h->t('Topics') ?></dt><dd><?php foreach (KafkaReadModel::groupTopics($group, $def) as $t): ?><span class="tag"><?= $h->e($t) ?></span><?php endforeach ?></dd>
            <dt><?= $h->t('Handlers') ?></dt><dd><?php if ($def['handlers'] === []): ?><span class="muted"><?= $h->t($group === 'platform-retry' ? 'built-in retry router' : 'none (events are acknowledged)') ?></span><?php endif ?><?php foreach ($def['handlers'] as $type => $class): ?><div class="small"><code><?= $h->e($type) ?></code> → <span class="mono"><?= $h->e($class) ?></span></div><?php endforeach ?></dd>
            <?php if ($state['paused']): ?><dt><?= $h->t('Paused') ?></dt><dd class="small"><?= $h->datetime($state['paused_at']) ?></dd><?php endif ?>
            <dt><?= $h->t('Processes') ?></dt><dd class="small"><?php if ($procs === []): ?><span class="muted">—</span><?php endif ?><?php foreach ($procs as $p): ?><div class="mono"><span class="dot dot--<?= $p['alive'] ? 'ok' : 'muted' ?>"></span> <?= $h->e($p['hostname']) ?>:<?= (int) $p['pid'] ?> · <?= $h->ago($p['heartbeat_at']) ?></div><?php endforeach ?></dd>
        </dl>
    </div>
    <div id="lag-<?= $h->e(preg_replace('/[^a-z0-9-]/i', '-', $group)) ?>" hx-get="<?= $h->url('admin.kafka.consumers.lag', ['group' => $group]) ?>" hx-trigger="load, every 30s" hx-swap="innerHTML">
        <div class="card__body small muted"><?= $h->t('Measuring lag…') ?></div>
    </div>
</div>
<?php endforeach ?>
