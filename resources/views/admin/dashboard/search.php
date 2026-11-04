<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $q
 * @var array<string, list<array{label:string, route:string, args:array, meta:string, query?:array}>> $results
 */

$this->setTitle($h->tr('Search'));
$this->setParameter('breadcrumbs', [$h->tr('Search')]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Search') ?></h1><p class="page-head__sub"><?= $h->t('Results for “{q}”', ['q' => $q]) ?></p></div></div>
<?php if ($results === []): ?>
    <div class="card"><div class="empty"><?= $h->icon('search') ?><p><?= mb_strlen($q) < 2 ? $h->t('Type at least 2 characters.') : $h->t('Nothing found.') ?></p></div></div>
<?php endif ?>
<?php foreach ($results as $group => $items): ?>
    <div class="card">
        <div class="card__head"><h2><?= $h->t($group) ?></h2><span class="muted small"><?= count($items) ?></span></div>
        <table class="table table--compact"><tbody>
        <?php foreach ($items as $r): ?>
            <tr><td><a href="<?= $h->url($r['route'], $r['args'], $r['query'] ?? []) ?>"><?= $h->e($r['label']) ?></a></td><td class="right small muted"><?= $h->e($r['meta']) ?></td></tr>
        <?php endforeach ?>
        </tbody></table>
    </div>
<?php endforeach ?>
