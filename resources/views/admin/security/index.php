<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array{id:string, title:string, status:string, detail:string, evidence:string}> $checks
 * @var array{pass:int, warn:int, fail:int, info:int, score:int} $summary
 * @var list<array<string,mixed>> $noMfa
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $byIp
 * @var list<array<string,mixed>> $byUser
 * @var list<array<string,mixed>> $locked
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Security Center'));
$this->setParameter('breadcrumbs', [$h->tr('Security Center')]);
$tone = static fn(string $s) => match ($s) { 'pass' => 'ok', 'warn' => 'warn', 'fail' => 'bad', default => 'info' };
$unlockForm = static function (string $userId) use ($h, $csrf): string {
    return '<form class="ops-inline" method="post" action="' . $h->e($h->url('admin.security.unlock', ['id' => $userId])) . '" data-confirm="' . $h->t('Unlock this account?') . '">'
        . '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '"><button class="btn btn--sm" type="submit">' . $h->t('Unlock') . '</button></form>';
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Security Center') ?></h1>
        <p class="page-head__sub"><?= $h->t('Posture computed from live configuration and data. Score {score}%.', ['score' => $summary['score']]) ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.security']) ?>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('shield') ?> <?= $h->t('Posture score') ?></div><div class="kpi__value" data-posture-score><?= (int) $summary['score'] ?>%</div>
        <div class="bar <?= $summary['score'] >= 85 ? 'bar--ok' : ($summary['score'] >= 60 ? 'bar--warn' : 'bar--bad') ?>"><span class="w-<?= (int) (round($summary['score'] / 5) * 5) ?>"></span></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('check') ?> <?= $h->t('Passed') ?></div><div class="kpi__value"><?= (int) $summary['pass'] ?></div><div class="kpi__meta"><?= $h->t('{n} informational', ['n' => $summary['info']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Warnings') ?></div><div class="kpi__value"><?= (int) $summary['warn'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('x') ?> <?= $h->t('Failed') ?></div><div class="kpi__value"><?= (int) $summary['fail'] ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Posture checklist') ?></h2></div>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Control') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Detail') ?></th><th><?= $h->t('Evidence') ?></th></tr></thead>
                <tbody>
                <?php foreach ($checks as $c): ?>
                    <tr data-check="<?= $h->e($c['id']) ?>" data-status="<?= $h->e($c['status']) ?>">
                        <td><?= $h->t($c['title']) ?></td>
                        <td><span class="badge badge--<?= $tone($c['status']) ?>"><?= $h->t(strtoupper($c['status'])) ?></span></td>
                        <td class="small"><?= $h->e($c['detail']) ?></td>
                        <td class="small mono muted"><?= $h->e($c['evidence']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        </div>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('Recent security events (30 days)') ?></h2>
                <?php if ($can('audit.view')): ?><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.audit', [], ['category' => 'security', 'from' => date('Y-m-d', strtotime('-30 days'))]) ?>"><?= $h->t('All in audit log') ?></a><?php endif ?></div>
            <?php if ($events === []): ?><div class="empty"><?= $h->t('No security events.') ?></div><?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Time') ?></th><th><?= $h->t('Event') ?></th><th><?= $h->t('Actor') ?></th><th><?= $h->t('IP') ?></th><th><?= $h->t('Result') ?></th></tr></thead>
                <tbody>
                <?php foreach ($events as $e): ?>
                    <tr><td class="small nowrap"><?php if ($can('audit.view')): ?><a href="<?= $h->url('admin.audit.view', ['id' => $e['id']]) ?>"><?= $h->ago($e['occurred_at']) ?></a><?php else: ?><?= $h->ago($e['occurred_at']) ?><?php endif ?></td>
                        <td><code><?= $h->e($e['action']) ?></code></td>
                        <td class="small"><?= $h->e($e['actor_name'] ?? ($e['actor_id'] ? substr((string) $e['actor_id'], 0, 8) : 'anonymous')) ?></td>
                        <td class="small mono"><?= $h->e($e['ip']) ?></td>
                        <td><?= $h->badge($e['result']) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>

        <div class="grid grid--2">
            <div class="card">
                <div class="card__head"><h2><?= $h->t('Failed sign-ins by IP (24 h)') ?></h2></div>
                <?php if ($byIp === []): ?><div class="empty small"><?= $h->t('None.') ?></div><?php else: ?>
                <table class="table table--compact"><thead><tr><th>IP</th><th class="num"><?= $h->t('Failures') ?></th><th class="num"><?= $h->t('Accounts') ?></th><th><?= $h->t('Last') ?></th></tr></thead><tbody>
                <?php foreach ($byIp as $r): ?><tr><td class="mono small"><?= $h->e($r['ip'] ?? '—') ?></td><td class="num"><?= (int) $r['failures'] ?></td><td class="num"><?= (int) $r['users'] ?></td><td class="small"><?= $h->ago($r['last']) ?></td></tr><?php endforeach ?>
                </tbody></table>
                <?php endif ?>
            </div>
            <div class="card">
                <div class="card__head"><h2><?= $h->t('Failed sign-ins by account (24 h)') ?></h2></div>
                <?php if ($byUser === []): ?><div class="empty small"><?= $h->t('None.') ?></div><?php else: ?>
                <table class="table table--compact"><thead><tr><th><?= $h->t('Login') ?></th><th class="num"><?= $h->t('Failures') ?></th><th class="num">IPs</th><th></th></tr></thead><tbody>
                <?php foreach ($byUser as $r): $isLocked = $r['locked'] === true || $r['locked'] === 't'; ?>
                    <tr><td class="small"><?= $h->e($r['username']) ?><?= $r['user_id'] === null ? ' <span class="muted">(' . $h->t('unknown') . ')</span>' : '' ?></td><td class="num"><?= (int) $r['failures'] ?></td><td class="num"><?= (int) $r['ips'] ?></td>
                        <td class="actions"><?php if ($isLocked): ?><?= $h->badge('blocked', $h->tr('locked')) ?><?php if ($can('security.manage')): ?> <?= $unlockForm((string) $r['user_id']) ?><?php endif ?><?php endif ?></td></tr>
                <?php endforeach ?>
                </tbody></table>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Privileged users without MFA') ?></h2></div>
            <?php if ($noMfa === []): ?><div class="empty small"><?= $h->icon('check') ?><p><?= $h->t('Every privileged user has MFA enrolled.') ?></p></div><?php else: ?>
            <table class="table table--compact"><tbody>
            <?php foreach ($noMfa as $u): ?>
                <tr><td><?= $h->e($u['full_name'] ?: $u['username']) ?><br><span class="small muted mono"><?= $h->e($u['username']) ?> · <?= $h->e($u['roles']) ?></span></td>
                    <td class="small right"><?= $h->ago($u['last_login_at']) ?></td></tr>
            <?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Locked accounts') ?></h2></div>
            <?php if ($locked === []): ?><div class="empty small"><?= $h->t('No locked accounts.') ?></div><?php else: ?>
            <table class="table table--compact"><tbody>
            <?php foreach ($locked as $u): ?>
                <tr data-locked-user="<?= $h->e($u['id']) ?>"><td><?= $h->e($u['username']) ?><br><span class="small muted"><?= $h->t('until {t}', ['t' => $h->datetime($u['locked_until'], 'H:i')]) ?> · <?= (int) $u['failed_logins'] ?> <?= $h->t('failures') ?></span></td>
                    <td class="actions"><?php if ($can('security.manage')): ?><?= $unlockForm((string) $u['id']) ?><?php endif ?></td></tr>
            <?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
    </div>
</div>
