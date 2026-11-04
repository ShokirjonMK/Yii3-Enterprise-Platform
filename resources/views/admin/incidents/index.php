<?php

declare(strict_types=1);

use App\Platform\Incidents\IncidentService;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{status:string, severity:string, q:string} $filters
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $oncall
 * @var array<string, list<string>> $errors
 * @var array<string,mixed> $old
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Incident Management'));
$this->setParameter('breadcrumbs', [$h->tr('Incident Management')]);
$query = array_filter($filters + ['sort' => $page->sort]);
$err = static fn(string $f) => $errors[$f][0] ?? null;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Incident Management') ?></h1>
        <p class="page-head__sub"><?= $h->t('Open → investigating → identified → monitoring → resolved → closed. Critical health failures open incidents automatically.') ?></p>
    </div>
</div>

<div class="grid grid--4" hx-get="<?= $h->url('admin.incidents', [], $query) ?>" hx-trigger="every 30s" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Active') ?></div><div class="kpi__value"><?= (int) ($counts['active'] ?? 0) ?></div><div class="kpi__meta"><?= $h->t('{n} sev1/sev2', ['n' => $counts['major'] ?? 0]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('bell') ?> <?= $h->t('Unacknowledged') ?></div><div class="kpi__value"><?= (int) ($counts['unacknowledged'] ?? 0) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('clock') ?> MTTA / MTTR</div><div class="kpi__value"><?= (int) ($counts['mtta_min'] ?? 0) ?> / <?= (int) ($counts['mttr_min'] ?? 0) ?></div><div class="kpi__meta"><?= $h->t('minutes, last 30 days') ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('check') ?> <?= $h->t('Resolved (30 d)') ?></div><div class="kpi__value"><?= (int) ($counts['resolved_30d'] ?? 0) ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="card">
        <form class="toolbar" method="get" action="<?= $h->url('admin.incidents') ?>" hx-get="<?= $h->url('admin.incidents') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
            <select name="status" aria-label="<?= $h->t('Status') ?>">
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>><?= $h->t('Active') ?></option>
                <option value="all"<?= $filters['status'] === 'all' ? ' selected' : '' ?>><?= $h->t('All') ?></option>
                <?php foreach (IncidentService::STATUSES as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
            </select>
            <select name="severity" aria-label="<?= $h->t('Severity') ?>"><option value=""><?= $h->t('Any severity') ?></option>
                <?php foreach (IncidentService::SEVERITIES as $s): ?><option value="<?= $s ?>"<?= $filters['severity'] === $s ? ' selected' : '' ?>><?= $h->e($s) ?></option><?php endforeach ?></select>
            <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Title, service, number') ?>" aria-label="<?= $h->t('Search') ?>">
            <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
        </form>
        <?php if ($page->items === []): ?>
            <div class="empty"><?= $h->icon('check') ?><p><?= $h->t('No incidents.') ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= $h->sortLink('#', 'number', $page->sort, $query, 'admin.incidents') ?></th>
                <th><?= $h->t('Title') ?></th>
                <th><?= $h->sortLink($h->tr('Severity'), 'severity', $page->sort, $query, 'admin.incidents') ?></th>
                <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.incidents') ?></th>
                <th><?= $h->sortLink($h->tr('Service'), 'service', $page->sort, $query, 'admin.incidents') ?></th>
                <th><?= $h->t('Commander') ?></th>
                <th><?= $h->sortLink($h->tr('Opened'), 'opened_at', $page->sort, $query, 'admin.incidents') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $i): ?>
                <tr data-incident="<?= $h->e($i['id']) ?>">
                    <td class="mono"><a href="<?= $h->url('admin.incidents.view', ['id' => $i['id']]) ?>">#<?= (int) $i['number'] ?></a></td>
                    <td><a href="<?= $h->url('admin.incidents.view', ['id' => $i['id']]) ?>"><?= $h->e($i['title']) ?></a><?= $i['source'] !== 'manual' ? ' <span class="tag">' . $h->e($i['source']) . '</span>' : '' ?><?= $i['acknowledged_at'] === null && !in_array($i['status'], ['resolved', 'closed'], true) ? ' <span class="tag">' . $h->t('unacknowledged') . '</span>' : '' ?></td>
                    <td><?= $h->badge($i['severity'], strtoupper((string) $i['severity'])) ?></td>
                    <td><?= $h->badge($i['status']) ?></td>
                    <td class="small mono"><?= $h->e($i['service']) ?></td>
                    <td class="small"><?= $h->e($i['commander'] ?? '—') ?></td>
                    <td class="small nowrap"><?= $h->ago($i['opened_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.incidents', 'query' => $query]) ?>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Open an incident') ?></h2></div>
        <div class="card__body">
        <?php if ($can('incidents.manage')): ?>
            <form class="form" method="post" action="<?= $h->url('admin.incidents.open') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <div class="field<?= $err('title') ? ' field--invalid' : '' ?>"><label for="i-t"><?= $h->t('Title') ?></label><input id="i-t" type="text" name="title" required maxlength="255" value="<?= $h->e($old['title'] ?? '') ?>">
                    <?php if ($err('title')): ?><div class="field__error"><?= $h->t($err('title')) ?></div><?php endif ?></div>
                <div class="field"><label for="i-s"><?= $h->t('Severity') ?></label>
                    <select id="i-s" name="severity"><?php foreach (['sev1' => 'SEV1 — critical outage', 'sev2' => 'SEV2 — major degradation', 'sev3' => 'SEV3 — minor impact', 'sev4' => 'SEV4 — low / cosmetic'] as $v => $l): ?><option value="<?= $v ?>"<?= ($old['severity'] ?? 'sev3') === $v ? ' selected' : '' ?>><?= $h->t($l) ?></option><?php endforeach ?></select></div>
                <div class="field<?= $err('service') ? ' field--invalid' : '' ?>"><label for="i-sv"><?= $h->t('Service') ?></label><input id="i-sv" type="text" name="service" maxlength="96" value="<?= $h->e($old['service'] ?? 'platform') ?>">
                    <?php if ($err('service')): ?><div class="field__error"><?= $h->t($err('service')) ?></div><?php endif ?></div>
                <div class="field"><label for="i-c"><?= $h->t('Incident commander') ?></label>
                    <select id="i-c" name="commander_id"><option value=""><?= $h->t('— first responder —') ?></option>
                        <?php foreach ($oncall as $u): ?><option value="<?= $h->e($u['id']) ?>"><?= $h->e(($u['full_name'] ?: $u['username']) . ' (' . $u['username'] . ')') ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="i-d"><?= $h->t('Description / impact') ?></label><textarea id="i-d" name="description" maxlength="20000"><?= $h->e($old['description'] ?? '') ?></textarea></div>
                <button class="btn btn--primary" type="submit"><?= $h->icon('alert') ?> <?= $h->t('Open incident') ?></button>
                <p class="small muted"><?= $h->t('{n} on-call users (holders of incidents.manage) will be notified.', ['n' => count($oncall)]) ?></p>
            </form>
        <?php else: ?>
            <p class="muted"><?= $h->t('You need the incidents.manage permission.') ?></p>
        <?php endif ?>
        </div>
    </div>
</div>
