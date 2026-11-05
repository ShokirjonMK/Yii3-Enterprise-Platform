<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{ok:bool, latency_ms:float, error:?string} $ping
 * @var array<string,mixed> $topology
 * @var bool $degraded
 * @var string|null $error
 * @var array{sections:array, derived:array}|null $info
 * @var list<array<string,mixed>> $slowlog
 * @var list<array<string,mixed>> $latency
 * @var array<string, array{label:string, keys:int, truncated:bool}> $namespaces
 * @var string $role
 * @var bool $cacheSeparate
 */

$this->setTitle($h->tr('Redis Management'));
$this->setParameter('breadcrumbs', [$h->tr('Redis Management')]);
$d = $info['derived'] ?? null;
$memPct = $d !== null && $d['maxmemory'] > 0 ? (int) round($d['used_memory'] / $d['maxmemory'] * 100) : null;
$uptime = (int) ($d['uptime_seconds'] ?? 0);
$showSections = ['Server', 'Clients', 'Memory', 'Persistence', 'Stats', 'Replication', 'CPU'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Redis Management') ?></h1>
        <p class="page-head__sub"><?= $h->t('Cache, sessions, locks and rate limits. Redis is never the system of record.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.redis', 'role' => $role, 'cacheSeparate' => $cacheSeparate]) ?>
<?php $policy = (string) ($d['maxmemory_policy'] ?? ''); ?>
<?php if ($d !== null && $role === 'state' && $policy !== 'noeviction'): ?>
    <div class="alert alert--warning" data-redis-warning="eviction"><?= $h->icon('alert') ?> <div><strong><?= $h->t('Sessions and locks can be evicted') ?></strong> —
        <?= $h->t('this instance uses maxmemory-policy {p}. When memory is full Redis deletes sessions (users are signed out), locks and one-time codes. Use noeviction here and a separate cache instance (REDIS_CACHE_HOST, allkeys-lfu).', ['p' => $policy]) ?></div></div>
<?php elseif ($d !== null && $role === 'state' && !$cacheSeparate): ?>
    <div class="alert alert--info"><?= $h->icon('info') ?> <div><?= $h->t('The cache shares this instance. Cache entries expire (REDIS_CACHE_DEFAULT_TTL), but a separate cache instance (REDIS_CACHE_HOST) is recommended for production.') ?></div></div>
<?php endif ?>

<?php if (!$ping['ok'] || $error !== null): ?>
    <div class="alert alert--error"><?= $h->icon('alert') ?> <div><strong><?= $h->t('Redis unreachable') ?></strong> — <span class="mono small"><?= $h->e($error) ?></span>
        <div class="small"><?= $h->t('Cache reads fall back to PostgreSQL; new sign-ins are refused until Redis is back (sessions fail closed).') ?></div></div></div>
<?php endif ?>
<?php if ($degraded): ?>
    <div class="alert alert--warning"><?= $h->icon('alert') ?> <div><?= $h->t('The cache is in degraded (pass-through) mode in this PHP process after a recent backend error.') ?></div></div>
<?php endif ?>

<div id="redis-live" hx-get="<?= $h->url('admin.redis') ?>" hx-trigger="every 30s" hx-select="#redis-live" hx-target="this" hx-swap="outerHTML">
<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('bolt') ?> <?= $h->t('Status') ?></div><div class="kpi__value"><?= $ping['ok'] ? $h->badge('healthy', $h->tr('Up')) : $h->badge('down', $h->tr('Down')) ?></div><div class="kpi__meta"><?= $h->t('PING {ms} ms · v{v}', ['ms' => $ping['latency_ms'], 'v' => $d['version'] ?? '?']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('database') ?> <?= $h->t('Memory') ?></div><div class="kpi__value"><?= $d === null ? '—' : $h->e($h->bytes($d['used_memory'])) ?></div>
        <?php if ($memPct !== null): ?><div class="bar<?= $memPct > 90 ? ' bar--bad' : ($memPct > 75 ? ' bar--warn' : ' bar--ok') ?>"><span class="w-<?= min(100, (int) (round($memPct / 5) * 5)) ?>"></span></div><?php endif ?>
        <div class="kpi__meta"><?= $d === null ? '' : $h->t('max {m} · policy {p}', ['m' => $d['maxmemory'] > 0 ? $h->bytes($d['maxmemory']) : $h->tr('unlimited'), 'p' => $d['maxmemory_policy'] ?? '?']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('activity') ?> <?= $h->t('Hit rate') ?></div><div class="kpi__value"><?= $d === null || $d['hit_rate'] === null ? '—' : $h->e($d['hit_rate']) . '%' ?></div><div class="kpi__meta"><?= $d === null ? '' : $h->t('{h} hits · {m} misses', ['h' => $d['hits'], 'm' => $d['misses']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('trash') ?> <?= $h->t('Evicted keys') ?></div><div class="kpi__value"><?= $d === null ? '—' : number_format((int) $d['evicted_keys'], 0, '.', ' ') ?></div><div class="kpi__meta"><?= $d === null ? '' : $h->t('{e} expired · {o} ops/s', ['e' => $d['expired_keys'], 'o' => $d['ops_per_sec']]) ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Slow log') ?></h2><span class="small muted"><?= $h->t('arguments masked') ?></span></div>
            <?php if ($slowlog === []): ?><div class="empty"><?= $h->t('Slow log is empty.') ?></div><?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th>#</th><th><?= $h->t('When') ?></th><th class="num"><?= $h->t('Duration') ?></th><th><?= $h->t('Command') ?></th><th><?= $h->t('Client') ?></th></tr></thead>
                <tbody>
                <?php foreach ($slowlog as $s): ?>
                    <tr><td class="num"><?= (int) $s['id'] ?></td><td class="small nowrap"><?= $h->ago('@' . $s['at']) ?></td><td class="num"><?= $h->e(round($s['duration_us'] / 1000, 2)) ?> ms</td><td><code><?= $h->e($s['command']) ?></code></td><td class="mono small"><?= $h->e($s['client'] ?? '—') ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Latency events') ?></h2><span class="small muted">LATENCY LATEST</span></div>
            <?php if ($latency === []): ?><div class="empty"><?= $h->t('No latency spikes above the monitor threshold.') ?></div><?php else: ?>
            <table class="table table--compact"><thead><tr><th><?= $h->t('Event') ?></th><th><?= $h->t('Last') ?></th><th class="num"><?= $h->t('Latest ms') ?></th><th class="num"><?= $h->t('Max ms') ?></th></tr></thead><tbody>
                <?php foreach ($latency as $l): ?><tr><td class="mono small"><?= $h->e($l['event']) ?></td><td class="small"><?= $h->ago('@' . $l['at']) ?></td><td class="num"><?= (int) $l['latest_ms'] ?></td><td class="num"><?= (int) $l['max_ms'] ?></td></tr><?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
        <?php if ($info !== null): ?>
        <div class="card">
            <div class="card__head"><h2>INFO</h2></div>
            <div class="card__body grid grid--2">
                <?php foreach ($showSections as $section): if (!isset($info['sections'][$section])) { continue; } ?>
                    <details class="runtime-info">
                        <summary><strong><?= $h->e($section) ?></strong> <span class="small muted">(<?= count($info['sections'][$section]) ?>)</span></summary>
                        <table class="table table--compact"><tbody>
                            <?php foreach ($info['sections'][$section] as $k => $v): ?><tr><td class="mono small"><?= $h->e($k) ?></td><td class="mono small"><?= $h->e($v) ?></td></tr><?php endforeach ?>
                        </tbody></table>
                    </details>
                <?php endforeach ?>
            </div>
        </div>
        <?php endif ?>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Topology') ?></h2></div>
            <div class="card__body"><dl class="dl runtime-dl">
                <dt><?= $h->t('Mode') ?></dt><dd><span class="tag"><?= $h->e($topology['mode']) ?></span></dd>
                <dt><?= $h->t('Endpoints') ?></dt><dd class="mono small"><?= $h->e(implode(', ', $topology['endpoints'])) ?></dd>
                <?php if ($topology['service']): ?><dt><?= $h->t('Sentinel service') ?></dt><dd class="mono small"><?= $h->e($topology['service']) ?></dd><?php endif ?>
                <dt><?= $h->t('Database') ?></dt><dd><?= (int) $topology['database'] ?></dd>
                <dt><?= $h->t('Key prefix') ?></dt><dd class="mono small"><?= $h->e($topology['prefix']) ?></dd>
                <dt><?= $h->t('AUTH') ?></dt><dd><?= $topology['auth'] ? $h->t('password set') : $h->t('none') ?><?= ($topology['acl_user'] ?? null) ? ' · ' . $h->t('ACL user {u}', ['u' => $topology['acl_user']]) : '' ?></dd>
                <dt><?= $h->t('Role') ?></dt><dd><span class="tag"><?= $h->e($role === 'cache' ? $h->tr('cache (evictable)') : ($cacheSeparate ? $h->tr('state: sessions, locks, rate limits') : $h->tr('state + cache (shared instance)'))) ?></span></dd>
                <dt><?= $h->t('TLS') ?></dt><dd><?= ($topology['tls'] ?? false) ? $h->badge('ok', $h->tr('on')) : $h->t('off') ?></dd>
                <dt><?= $h->t('Persistent connections') ?></dt><dd><?= ($topology['persistent'] ?? false) ? $h->t('on (PHP-FPM)') : $h->t('off') ?></dd>
                <?php if ($d !== null): ?>
                <dt><?= $h->t('Role') ?></dt><dd><?= $h->e($d['role']) ?> · <?= $h->t('{n} replicas', ['n' => $d['connected_slaves']]) ?></dd>
                <dt><?= $h->t('Uptime') ?></dt><dd><?= $h->e(sprintf('%dd %02dh', intdiv($uptime, 86400), intdiv($uptime % 86400, 3600))) ?></dd>
                <dt><?= $h->t('Clients') ?></dt><dd><?= (int) $d['connected_clients'] ?> (<?= $h->t('{n} blocked', ['n' => $d['blocked_clients']]) ?>)</dd>
                <dt><?= $h->t('Persistence') ?></dt><dd>AOF <?= $d['aof_enabled'] ? $h->badge('enabled', 'on') : $h->badge('disabled', 'off') ?> · RDB <?= $h->e($d['rdb_last_status'] ?? '—') ?><div class="small muted"><?= $d['rdb_last_save'] ? $h->t('last save {t}', ['t' => $h->ago('@' . $d['rdb_last_save'])]) : '' ?></div></dd>
                <dt><?= $h->t('Fragmentation') ?></dt><dd><?= $h->e($d['fragmentation_ratio'] ?? '—') ?></dd>
                <?php endif ?>
            </dl></div>
        </div>
        <?php if ($namespaces !== []): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Namespaces') ?></h2></div>
            <table class="table table--compact"><tbody>
                <?php foreach ($namespaces as $ns => $n): ?><tr><td><a class="mono small" href="<?= $h->url('admin.redis.keys', [], ['prefix' => $ns]) ?>"><?= $h->e($ns) ?></a><div class="small muted"><?= $h->t($n['label']) ?></div></td><td class="num"><?= (int) $n['keys'] ?><?= $n['truncated'] ? '+' : '' ?></td></tr><?php endforeach ?>
            </tbody></table>
        </div>
        <?php endif ?>
        <?php if ($d !== null && $d['keyspace'] !== []): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Keyspace') ?></h2></div>
            <table class="table table--compact"><tbody>
                <?php foreach ($d['keyspace'] as $db => $k): ?><tr><td class="mono small"><?= $h->e($db) ?></td><td class="num small"><?= $h->t('{k} keys · {e} with TTL', ['k' => $k['keys'] ?? 0, 'e' => $k['expires'] ?? 0]) ?></td></tr><?php endforeach ?>
            </tbody></table>
        </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Outage policy') ?></h2></div>
            <div class="card__body small">
                <ul class="runtime-list">
                    <li><strong><?= $h->t('Cache — fail-open.') ?></strong> <?= $h->t('Errors are treated as cache misses; the backend is bypassed for 10 s (FailSafeCache) and data is recomputed from PostgreSQL.') ?></li>
                    <li><strong><?= $h->t('Sessions — fail-closed.') ?></strong> <?= $h->t('Without Redis no session can be read or written: users must sign in again once Redis is back. No silent fallback to local files (would break multi-node consistency).') ?></li>
                    <li><strong><?= $h->t('Locks — efficiency only.') ?></strong> <?= $h->t('Redis locks avoid duplicate work; correctness-critical exclusion (scheduler leader) uses PostgreSQL advisory locks, so a Redis outage never runs a schedule twice.') ?></li>
                    <li><strong><?= $h->t('Rate limits.') ?></strong> <?= $h->t('General API limits fail open (logged); login throttling is additionally enforced in PostgreSQL, so brute-force protection stays active.') ?></li>
                    <li><strong><?= $h->t('Metrics.') ?></strong> <?= $h->t('Counters in Redis are lost on outage; Prometheus scrapes resume afterwards.') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<p class="small muted"><?= $h->t('Updated {time}', ['time' => date('H:i:s')]) ?></p>
</div>
