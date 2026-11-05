<?php

declare(strict_types=1);

use App\Platform\Deployments\DeploymentService;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{environment:string, status:string} $filters
 * @var array<string, array|null> $current
 * @var array{configured:bool, pipelines:list<array>, error:?string, url:string, project:string} $gitlab
 * @var array<string, list<string>> $errors
 * @var array<string, mixed> $old
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('CI/CD & Deployments'));
$this->setParameter('breadcrumbs', [$h->tr('CI/CD & Deployments')]);
$query = array_filter($filters + ['sort' => $page->sort]);
$err = static fn(string $f) => $errors[$f][0] ?? null;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('CI/CD & Deployments') ?></h1>
        <p class="page-head__sub"><?= $h->t('Deployments are requested here, approved by a second person (four-eyes) and executed by the GitLab pipeline — the platform never connects to servers.') ?></p>
    </div>
</div>

<div class="grid grid--3">
    <?php foreach ($current as $env => $d): ?>
        <div class="card kpi" data-env="<?= $h->e($env) ?>">
            <div class="kpi__label"><?= $h->icon('rocket') ?> <?= $h->e(ucfirst($env)) ?></div>
            <div class="kpi__value mono"><?= $d ? $h->e($d['version']) : '—' ?></div>
            <div class="kpi__meta"><?= $d ? $h->t('deployed {ago}', ['ago' => $h->ago($d['finished_at'] ?? $d['updated_at'])]) : $h->t('no successful deployment yet') ?></div>
        </div>
    <?php endforeach ?>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <form class="toolbar" method="get" action="<?= $h->url('admin.deployments') ?>" hx-get="<?= $h->url('admin.deployments') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
                <select name="environment" aria-label="<?= $h->t('Environment') ?>"><option value=""><?= $h->t('All environments') ?></option>
                    <?php foreach (DeploymentService::ENVIRONMENTS as $e): ?><option value="<?= $e ?>"<?= $filters['environment'] === $e ? ' selected' : '' ?>><?= $h->e($e) ?></option><?php endforeach ?></select>
                <select name="status" aria-label="<?= $h->t('Status') ?>"><option value=""><?= $h->t('Any status') ?></option>
                    <?php foreach (['requested', 'approved', 'rejected', 'running', 'succeeded', 'failed', 'rolled_back'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?></select>
                <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
            </form>
            <?php if ($page->items === []): ?>
                <div class="empty"><?= $h->icon('rocket') ?><p><?= $h->t('No deployments recorded.') ?></p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr>
                    <th><?= $h->sortLink($h->tr('Requested'), 'created_at', $page->sort, $query, 'admin.deployments') ?></th>
                    <th><?= $h->sortLink($h->tr('Environment'), 'environment', $page->sort, $query, 'admin.deployments') ?></th>
                    <th><?= $h->sortLink($h->tr('Version'), 'version', $page->sort, $query, 'admin.deployments') ?></th>
                    <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.deployments') ?></th>
                    <th><?= $h->t('Requested by') ?></th><th><?= $h->t('Approved by') ?></th><th><?= $h->t('Pipeline') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($page->items as $d): ?>
                    <tr data-deployment="<?= $h->e($d['id']) ?>">
                        <td class="nowrap"><a href="<?= $h->url('admin.deployments.view', ['id' => $d['id']]) ?>"><?= $h->datetime($d['created_at']) ?></a></td>
                        <td><span class="tag"><?= $h->e($d['environment']) ?></span></td>
                        <td class="mono"><?= $h->e($d['version']) ?><?= $d['rollback_of'] ? ' <span class="tag">rollback</span>' : '' ?></td>
                        <td><?= $h->badge($d['status']) ?></td>
                        <td class="small"><?= $h->e($d['requester'] ?? '—') ?></td>
                        <td class="small"><?= $h->e($d['approver'] ?? $d['rejecter'] ?? '—') ?></td>
                        <td class="small"><?php if ($d['pipeline_url']): ?><a href="<?= $h->e($d['pipeline_url']) ?>" target="_blank" rel="noopener noreferrer">#<?= (int) $d['pipeline_id'] ?></a><?php else: ?>—<?php endif ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
            <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.deployments', 'query' => $query]) ?>
        </div>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('Recent GitLab pipelines') ?></h2><?php if ($gitlab['configured']): ?><span class="small muted mono"><?= $h->e($gitlab['url']) ?> · <?= $h->e($gitlab['project']) ?></span><?php endif ?></div>
            <?php if (!$gitlab['configured']): ?>
                <div class="empty" data-gitlab="not-configured"><p><strong><?= $h->t('Not configured') ?></strong></p>
                    <p class="small"><?= $h->t('Set ops.gitlab_url, ops.gitlab_project_id and ops.gitlab_token (System Settings → Operations or GITLAB_* env). Approved deployments wait until GitLab is configured.') ?></p></div>
            <?php elseif ($gitlab['error']): ?>
                <div class="card__body"><div class="alert alert--error"><?= $h->e($gitlab['error']) ?></div></div>
            <?php else: ?>
                <table class="table table--compact"><tbody>
                <?php foreach ($gitlab['pipelines'] as $p): ?>
                    <tr><td><a href="<?= $h->e($p['web_url']) ?>" target="_blank" rel="noopener noreferrer">#<?= (int) $p['id'] ?></a></td><td class="mono small"><?= $h->e($p['ref']) ?></td>
                        <td><?= $h->badge(App\Platform\Deployments\GitLabClient::mapStatus($p['status']), $p['status']) ?></td><td class="small"><?= $h->e($p['source'] ?? '') ?></td><td class="small"><?= $h->ago($p['created_at']) ?></td></tr>
                <?php endforeach ?>
                </tbody></table>
            <?php endif ?>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2><?= $h->t('Request a deployment') ?></h2></div>
        <div class="card__body">
        <?php if ($can('deploy.request')): ?>
            <form class="form" method="post" action="<?= $h->url('admin.deployments.request') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <div class="field<?= $err('environment') ? ' field--invalid' : '' ?>"><label for="d-env"><?= $h->t('Environment') ?></label>
                    <select id="d-env" name="environment" required><?php foreach (DeploymentService::ENVIRONMENTS as $e): ?><option value="<?= $e ?>"<?= ($old['environment'] ?? 'staging') === $e ? ' selected' : '' ?>><?= $h->e($e) ?></option><?php endforeach ?></select>
                    <?php if ($err('environment')): ?><div class="field__error"><?= $h->t($err('environment')) ?></div><?php endif ?></div>
                <div class="field<?= $err('version') ? ' field--invalid' : '' ?>"><label for="d-ver"><?= $h->t('Version (release / image tag)') ?></label>
                    <input id="d-ver" type="text" name="version" required maxlength="64" value="<?= $h->e($old['version'] ?? '') ?>" placeholder="v1.4.2">
                    <?php if ($err('version')): ?><div class="field__error"><?= $h->t($err('version')) ?></div><?php endif ?></div>
                <div class="field<?= $err('commit_sha') ? ' field--invalid' : '' ?>"><label for="d-sha"><?= $h->t('Commit SHA (optional)') ?></label>
                    <input id="d-sha" type="text" name="commit_sha" maxlength="64" value="<?= $h->e($old['commit_sha'] ?? '') ?>">
                    <?php if ($err('commit_sha')): ?><div class="field__error"><?= $h->t($err('commit_sha')) ?></div><?php endif ?></div>
                <div class="field"><label for="d-str"><?= $h->t('Strategy') ?></label>
                    <select id="d-str" name="strategy"><?php foreach (DeploymentService::STRATEGIES as $s): ?><option value="<?= $s ?>"<?= ($old['strategy'] ?? 'rolling') === $s ? ' selected' : '' ?>><?= $h->e($s) ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="d-notes"><?= $h->t('Change notes') ?></label><textarea id="d-notes" name="notes" maxlength="2000"><?= $h->e($old['notes'] ?? '') ?></textarea></div>
                <button class="btn btn--primary" type="submit"><?= $h->icon('rocket') ?> <?= $h->t('Request deployment') ?></button>
            </form>
        <?php else: ?>
            <p class="muted"><?= $h->t('You need the deploy.request permission.') ?></p>
        <?php endif ?>
        </div>
    </div>
</div>
