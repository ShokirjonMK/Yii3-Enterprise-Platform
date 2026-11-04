<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $row
 * @var mixed $before
 * @var mixed $after
 * @var array $metadata
 * @var list<array{path:string, before:mixed, after:mixed, change:string}> $diff
 * @var bool $hmacValid
 */

$this->setTitle($h->tr('Audit record #{id}', ['id' => $row['id']]));
$this->setParameter('breadcrumbs', [$h->tr('Audit & Compliance') => $h->url('admin.audit'), '#' . $row['id']]);
$val = static fn($v): string => $v === null ? '—' : (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><code><?= $h->e($row['action']) ?></code></h1>
        <p class="page-head__sub"><?= $h->datetime($row['occurred_at'], 'Y-m-d H:i:s') ?> · <?= $h->badge($row['result']) ?> <?= $h->badge($row['severity']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($hmacValid): ?>
            <span class="badge badge--ok"><?= $h->t('Signature valid') ?></span>
        <?php else: ?>
            <span class="badge badge--bad"><?= $h->t('Signature INVALID — record was modified') ?></span>
        <?php endif ?>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Changes') ?></h2><span class="small muted"><?= $h->t('Secrets are redacted at write time.') ?></span></div>
            <?php if ($diff === []): ?>
                <div class="empty"><?= $h->t('This record carries no before/after state.') ?></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table table--compact ops-diff">
                    <thead><tr><th><?= $h->t('Field') ?></th><th><?= $h->t('Before') ?></th><th><?= $h->t('After') ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($diff as $d): ?>
                        <tr class="ops-diff__<?= $h->e($d['change']) ?>">
                            <td class="mono"><?= $h->e($d['path']) ?></td>
                            <td class="mono ops-diff__before"><?= $h->e($val($d['before'])) ?></td>
                            <td class="mono ops-diff__after"><?= $h->e($val($d['after'])) ?></td>
                            <td><span class="tag"><?= $h->t($d['change']) ?></span></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table></div>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Metadata') ?></h2></div>
            <div class="card__body"><pre class="ops-pre"><?= $h->json($metadata) ?></pre></div>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Context') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Record id') ?></dt><dd class="mono"><?= $h->e($row['id']) ?></dd>
                <dt><?= $h->t('Category') ?></dt><dd><span class="tag"><?= $h->e($row['category']) ?></span></dd>
                <dt><?= $h->t('Actor') ?></dt><dd><?= $h->e($row['actor'] ?? 'system') ?> <span class="small muted">(<?= $h->e($row['actor_type']) ?>)</span><?php if ($row['actor_id']): ?><br><a class="small mono" href="<?= $h->url('admin.audit', [], ['actor' => $row['actor_id']]) ?>"><?= $h->e($row['actor_id']) ?></a><?php endif ?></dd>
                <dt><?= $h->t('Tenant') ?></dt><dd class="mono small"><?= $h->e($row['tenant_id'] ?? '—') ?></dd>
                <dt><?= $h->t('Entity') ?></dt><dd><?= $h->e($row['entity_type'] ?? '—') ?><?php if ($row['entity_id'] !== null): ?> · <a class="mono small" href="<?= $h->url('admin.audit', [], ['entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id']]) ?>"><?= $h->e($row['entity_id']) ?></a><?php endif ?></dd>
                <dt><?= $h->t('IP') ?></dt><dd class="mono"><?= $h->e($row['ip_host'] ?? '—') ?></dd>
                <dt><?= $h->t('User agent') ?></dt><dd class="small"><?= $h->e($row['user_agent'] ?? '—') ?></dd>
                <dt><?= $h->t('Correlation id') ?></dt><dd><?php if ($row['correlation_id']): ?><a class="mono small" href="<?= $h->url('admin.audit', [], ['correlation_id' => $row['correlation_id']]) ?>"><?= $h->e($row['correlation_id']) ?></a><?php else: ?>—<?php endif ?></dd>
                <dt><?= $h->t('HMAC') ?></dt><dd class="mono small ops-break"><?= $h->e($row['row_hmac']) ?></dd>
            </dl>
        </div>
    </div>
</div>
