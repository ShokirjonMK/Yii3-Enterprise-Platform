<?php

declare(strict_types=1);

use App\Platform\Database\Connections\DatabaseDriver;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Platform\Database\Connections\ConnectionDefinition $d
 * @var string $statement
 * @var bool $write
 * @var int $limit
 * @var bool $canWrite
 * @var array{columns:list<string>, rows:list<array<string,?string>>, truncated:bool, affected:?int, duration_ms:int, error:?string}|null $result
 * @var array<string, list<string>> $errors
 * @var string $csrf
 */
$errors ??= [];
$this->setTitle($h->tr('Query console') . ' · ' . $d->name);
$this->setParameter('breadcrumbs', [$h->tr('Databases') => $h->url('admin.databases'), $d->name => $h->url('admin.databases.show', ['name' => $d->name]), $h->tr('Query console')]);
$mongo = $d->family() === 'document';
$placeholder = $mongo ? '{"collection": "users", "find": {"active": true}, "sort": {"_id": -1}}' : 'SELECT * FROM … LIMIT 100';
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Query console') ?> <span class="tag"><?= $h->e($d->name) ?></span> <span class="tag"><?= $h->e(DatabaseDriver::label($d->driver)) ?></span></h1>
    <p class="page-head__sub"><?= $h->t('Read mode runs one statement in a read-only transaction that is always rolled back. Every run is written to the audit log.') ?></p>
</div></div>

<form method="post" action="<?= $h->url('admin.databases.console.run', ['name' => $d->name]) ?>" class="card mb">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form">
        <div class="field<?= isset($errors['statement']) ? ' field--invalid' : '' ?>"><label for="f-stmt"><?= $mongo ? $h->t('Query (JSON)') : $h->t('SQL') ?></label>
            <textarea class="code" id="f-stmt" name="statement" rows="8" required placeholder="<?= $h->e($placeholder) ?>"><?= $h->e($statement) ?></textarea>
            <?php if ($mongo): ?><div class="field__hint"><?= $h->t('find / aggregate / count; write mode: insertOne, insertMany, updateMany {filter, update}, deleteMany, command.') ?></div><?php endif ?>
            <?php if (isset($errors['statement'])): ?><div class="field__error"><?= $h->e(implode(' ', $errors['statement'])) ?></div><?php endif ?></div>
        <div class="row">
            <label class="check"><input type="radio" name="mode" value="read"<?= $write ? '' : ' checked' ?>> <?= $h->t('Read') ?></label>
            <?php if ($canWrite): ?>
                <label class="check"><input type="radio" name="mode" value="write"<?= $write ? ' checked' : '' ?>> <?= $h->t('Write (changes data, committed)') ?></label>
            <?php else: ?>
                <span class="small muted"><?= $d->readOnly ? $h->t('Read-only connection.') : $h->t('Write mode needs the databases.write permission (superadmin).') ?></span>
            <?php endif ?>
            <label for="f-limit" class="small"><?= $h->t('Max rows') ?></label>
            <input type="number" id="f-limit" name="limit" min="1" max="1000" value="<?= (int) $limit ?>" class="input--sm">
        </div>
    </div>
    <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Run') ?></button></div>
</form>

<?php if ($result !== null): ?>
<div class="card" id="result">
    <div class="card__head"><h2><?= $h->t('Result') ?></h2>
        <span class="small muted"><?= (int) $result['duration_ms'] ?> ms<?= $result['error'] === null && $result['columns'] !== [] ? ' · ' . $h->t('{n} row(s)', ['n' => (string) count($result['rows'])]) : '' ?><?= $result['truncated'] ? ' · ' . $h->t('truncated') : '' ?></span></div>
    <?php if ($result['error'] !== null): ?>
        <div class="card__body"><div class="alert alert--error" data-query-status="error"><?= $h->e($result['error']) ?></div></div>
    <?php elseif ($result['columns'] === []): ?>
        <div class="card__body"><div class="alert alert--success" data-query-status="ok"><?= $result['affected'] === null ? $h->t('Statement executed.') : $h->t('Done — {n} row(s)/document(s) affected.', ['n' => (string) $result['affected']]) ?></div></div>
    <?php else: ?>
        <div class="table-wrap" data-query-status="ok"><table class="table table--compact">
            <thead><tr><?php foreach ($result['columns'] as $c): ?><th class="mono"><?= $h->e($c) ?></th><?php endforeach ?></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr><?php foreach ($result['columns'] as $c): ?><td class="mono small"><?= $row[$c] === null ? '<span class="muted">NULL</span>' : $h->e($row[$c]) ?></td><?php endforeach ?></tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>
<?php endif ?>
