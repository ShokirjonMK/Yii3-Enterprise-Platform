<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $row
 * @var array $breaker
 * @var bool $hasSecret
 * @var App\Shared\Infrastructure\Database\Page $logs
 * @var array $query
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($row['name']);
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $row['code']]);
$enabled = Sql::bool($row['enabled']);
$manage = $can('integrations.manage');
$auth = (array) Sql::json($row['auth_config'] ?? null);
$headers = (array) Sql::json($row['headers'] ?? null);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($row['name']) ?> <span class="tag"><?= $h->e($row['code']) ?></span></h1>
        <p class="page-head__sub row"><?= $enabled ? $h->badge('enabled') : $h->badge('disabled') ?> <?= $h->badge($row['status']) ?> <?= $this->render('./_breaker', ['breaker' => $breaker]) ?></p>
    </div>
    <?php if ($manage): ?>
    <div class="page-head__actions">
        <button class="btn" hx-post="<?= $h->url('admin.integrations.test', ['id' => $row['id']]) ?>" hx-target="#test-result" hx-swap="outerHTML"><?= $h->icon('play') ?> <?= $h->t('Test connection') ?></button>
        <a class="btn" href="<?= $h->url('admin.integrations.edit', ['id' => $row['id']]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
        <form method="post" action="<?= $h->url('admin.integrations.toggle', ['id' => $row['id']]) ?>"<?= $enabled ? ' data-confirm="' . $h->t('Disable this integration? Calls will fail with "not configured".') . '"' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
            <button class="btn<?= $enabled ? ' btn--danger' : ' btn--primary' ?>" type="submit"><?= $h->icon($enabled ? 'pause' : 'play') ?> <?= $enabled ? $h->t('Disable') : $h->t('Enable') ?></button>
        </form>
    </div>
    <?php endif ?>
</div>
<div id="test-result"></div>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Configuration') ?></h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt><?= $h->t('Base URL') ?></dt><dd class="mono"><?= $h->e($row['base_url'] ?? '—') ?></dd>
                    <dt><?= $h->t('Health path') ?></dt><dd class="mono"><?= $h->e($row['health_path'] ?? '—') ?></dd>
                    <dt><?= $h->t('Authentication') ?></dt><dd><span class="tag"><?= $h->e($row['auth_type']) ?></span>
                        <?php foreach ($auth as $k => $val): ?><span class="tag"><?= $h->e($k . '=' . $val) ?></span><?php endforeach ?>
                        <?= $row['auth_type'] === 'none' ? '' : ($hasSecret ? $h->badge('ok', $h->tr('secret set')) : $h->badge('failed', $h->tr('secret missing'))) ?></dd>
                    <dt><?= $h->t('Extra headers') ?></dt><dd><?php if ($headers === []): ?>—<?php else: foreach ($headers as $k => $val): ?><span class="tag"><?= $h->e($k . ': ' . $val) ?></span><?php endforeach; endif ?></dd>
                    <dt><?= $h->t('Timeouts') ?></dt><dd><?= $h->t('connect {c} ms · total {t} ms', ['c' => $row['connect_timeout_ms'], 't' => $row['timeout_ms']]) ?></dd>
                    <dt><?= $h->t('Retries') ?></dt><dd><?= $h->t('{n} × (backoff {b} ms, exponential + jitter, idempotent methods only)', ['n' => $row['max_retries'], 'b' => $row['backoff_ms']]) ?></dd>
                    <dt><?= $h->t('Circuit breaker') ?></dt><dd><?= $h->t('opens after {n} failures, cooldown {s} s', ['n' => $row['circuit_threshold'], 's' => $row['circuit_cooldown_s']]) ?></dd>
                    <dt><?= $h->t('Rate limit') ?></dt><dd><?= (int) $row['rate_limit_per_minute'] === 0 ? $h->t('unlimited') : $h->t('{n} requests/min', ['n' => $row['rate_limit_per_minute']]) ?></dd>
                    <dt><?= $h->t('Private network') ?></dt><dd><?= Sql::bool($row['allow_private_network']) ? $h->badge('warning', $h->tr('allowed')) : $h->badge('ok', $h->tr('blocked (SSRF guard)')) ?></dd>
                    <dt><?= $h->t('Last health check') ?></dt><dd><?= $h->datetime($row['last_health_at']) ?><?php if ($row['last_error']): ?><div class="small field__error"><?= $h->e($row['last_error']) ?></div><?php endif ?></dd>
                    <?php if ($row['description'] !== ''): ?><dt><?= $h->t('Description') ?></dt><dd><?= $h->e($row['description']) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>

        <div class="card" id="calls">
            <div class="card__head"><h2><?= $h->t('Recent calls') ?></h2><span class="small muted"><?= $h->t('Headers and bodies are redacted; samples ≤ 2 KB') ?></span></div>
            <?= $this->render('./_logs-table', ['page' => $logs, 'query' => $query, 'route' => 'admin.integrations.show', 'args' => ['id' => $row['id']], 'showIntegration' => false]) ?>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Circuit breaker') ?></h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt><?= $h->t('State') ?></dt><dd><?= $this->render('./_breaker', ['breaker' => $breaker]) ?></dd>
                    <dt><?= $h->t('Recent failures') ?></dt><dd><?= (int) $breaker['failures'] ?> / <?= (int) $row['circuit_threshold'] ?></dd>
                    <?php if ($breaker['opened_at']): ?><dt><?= $h->t('Opened') ?></dt><dd><?= $h->ago('@' . $breaker['opened_at']) ?></dd><?php endif ?>
                    <?php if (!$breaker['available']): ?><dt><?= $h->t('Backend') ?></dt><dd><?= $h->badge('degraded', $h->tr('Redis unavailable — fail-open')) ?></dd><?php endif ?>
                </dl>
                <?php if ($manage && $breaker['state'] !== 'closed'): ?>
                <form method="post" action="<?= $h->url('admin.integrations.circuit_reset', ['id' => $row['id']]) ?>" class="mt" data-confirm="<?= $h->t('Close the circuit and allow calls again?') ?>">
                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                    <button class="btn btn--sm" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Reset circuit') ?></button>
                </form>
                <?php endif ?>
            </div>
        </div>

        <?php if ($manage): ?>
        <form class="card" method="post" action="<?= $h->url('admin.integrations.security', ['id' => $row['id']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="card__head"><h2><?= $h->icon('lock') ?> <?= $h->t('Security') ?></h2></div>
            <div class="card__body form">
                <div class="field"><label for="f-secret"><?= $h->t('Secret / credential') ?></label>
                    <input type="password" id="f-secret" name="secret" autocomplete="new-password" placeholder="<?= $hasSecret ? $h->t('•••••••• (unchanged)') : $h->t('not set') ?>">
                    <div class="field__hint"><?= $h->t('Write-only. Stored encrypted (XChaCha20-Poly1305). Leave empty to keep the current value.') ?></div></div>
                <?php if ($hasSecret): ?><label class="check"><input type="checkbox" name="clear_secret" value="1"> <?= $h->t('Remove the stored secret') ?></label><?php endif ?>
                <label class="check"><input type="checkbox" name="allow_private_network" value="1"<?= Sql::bool($row['allow_private_network']) ? ' checked' : '' ?>> <?= $h->t('Allow private/internal network destinations') ?></label>
                <div class="field__hint"><?= $h->t('Only for internal services. Cloud metadata addresses stay blocked. Requires recent re-authentication.') ?></div>
            </div>
            <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('shield') ?> <?= $h->t('Save security settings') ?></button></div>
        </form>

        <form class="card" method="post" action="<?= $h->url('admin.integrations.delete', ['id' => $row['id']]) ?>" data-confirm="<?= $h->t('Delete integration {code}?', ['code' => $row['code']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="card__body row row--between"><span class="small muted"><?= $h->t('Deleting removes the configuration and its call log.') ?></span>
                <button class="btn btn--danger btn--sm" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></div>
        </form>
        <?php endif ?>
    </div>
</div>
