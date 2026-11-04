<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string, array{topics:list<string>, handlers:array<string,string>, raw?:array<string,list<string>>}> $groups
 * @var list<array{class:string, topic:string, group:string, events:list<string>, mode:string, description:string}> $discovered
 * @var array<string,bool> $paused
 * @var array<string,int> $alive
 * @var App\Platform\Messaging\Kafka\KafkaConfig $config
 */

use App\Platform\Messaging\Kafka\KafkaConsumerRunner;

$this->setTitle($h->tr('Kafka handlers'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Handlers')]);
$byClass = [];
foreach ($discovered as $d) {
    $byClass[$d['class'] . '|' . $d['group'] . '|' . $d['topic']] = $d;
}
$short = static fn(string $c) => substr($c, (int) strrpos($c, '\\') + 1);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Kafka handlers') ?></h1>
        <p class="page-head__sub"><?= $h->t('Consumer groups and the handlers they run. Classes with #[KafkaChannel] are discovered automatically; kafka:consume --group=all starts one process per group.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.handlers']) ?>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('stream') ?> <?= $h->t('Consumer groups') ?></div><div class="kpi__value"><?= count($groups) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('code') ?> <?= $h->t('#[KafkaChannel] handlers') ?></div><div class="kpi__value"><?= count($discovered) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('refresh') ?> <?= $h->t('Immediate attempts') ?></div><div class="kpi__value"><?= (int) $config->retryAttempts ?> × <?= (int) $config->retryBackoffMs ?> ms</div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('On failure') ?></div><div class="kpi__value small"><?= $h->e($config->onFailure) ?> · <?= (int) $config->maxDeliveries ?></div></div>
</div>

<div class="card mb">
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Group') ?></th><th><?= $h->t('Topic') ?></th><th><?= $h->t('Handler') ?></th><th><?= $h->t('Receives') ?></th><th><?= $h->t('Declared in') ?></th><th><?= $h->t('Status') ?></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group => $def):
            $rows = [];
            foreach ($def['handlers'] as $type => $class) {
                foreach ($def['topics'] as $t) {
                    if (isset($byClass["$class|$group|$t"])) {
                        $rows["$class|$t"] = [$t, $class, $type === '*' ? $h->tr('all platform events') : $type, 'attribute', $byClass["$class|$group|$t"]['description']];
                        continue 2;
                    }
                }
                $rows["$class|$type"] = [implode(', ', $def['topics']), $class, $type === '*' ? $h->tr('all platform events') : $type, 'config', ''];
            }
            foreach ($def['raw'] ?? [] as $t => $classes) {
                foreach ($classes as $class) {
                    $rows["$class|$t"] = [$t, $class, $h->tr('JSON messages'), 'attribute', $byClass["$class|$group|$t"]['description'] ?? ''];
                }
            }
            if ($rows === []) {
                $rows[] = [$group === 'platform-retry' ? implode(', ', array_keys(KafkaConsumerRunner::RETRY_TOPICS)) : implode(', ', $def['topics']), null, $group === 'platform-retry' ? $h->tr('delayed redelivery') : '—', 'config', ''];
            }
            $first = true;
            foreach ($rows as [$topic, $class, $receives, $source, $desc]): ?>
            <tr>
                <td class="mono"><?= $first ? $h->e($group) : '' ?></td>
                <td class="mono small"><?= $h->e($config->topic($topic)) ?></td>
                <td><?php if ($class): ?><strong><?= $h->e($short($class)) ?></strong><div class="small muted mono"><?= $h->e($class) ?></div><?php if ($desc): ?><div class="small"><?= $h->e($desc) ?></div><?php endif ?><?php else: ?>—<?php endif ?></td>
                <td class="small"><?= $h->e($receives) ?></td>
                <td><span class="tag"><?= $source === 'attribute' ? '#[KafkaChannel]' : 'events.php' ?></span></td>
                <td><?php if ($first): ?><?= ($paused[$group] ?? false) ? $h->badge('paused', $h->tr('Paused')) : $h->badge('active', $h->tr('Active')) ?> <span class="small muted"><?= $h->t('{n} process(es)', ['n' => (int) ($alive[$group] ?? 0)]) ?></span><?php endif ?></td>
            </tr>
        <?php $first = false; endforeach; endforeach ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Adding a handler') ?></h2></div>
    <div class="card__body">
        <p class="small"><?= $h->t('Create a class anywhere under src/Platform or src/Modules — no configuration needed. Restart the consumer service (or use --group=<name>) to pick it up.') ?></p>
<pre class="code">#[KafkaChannel(topic: 'orders.created', group: 'billing')]
final class OrderCreatedHandler implements KafkaMessageHandlerInterface
{
    public function handle(array $payload, KafkaMessage $message): void
    {
        // runs once per message (inbox), inside a DB transaction;
        // throw → retried; throw PermanentMessageFailure → dead letter at once
    }
}</pre>
        <p class="small muted"><?= $h->t('Register the topic in “Cluster & topics” and run the provisioning script; topics are not auto-created.') ?></p>
    </div>
</div>
