<?php

declare(strict_types=1);

/**
 * Pagination with HTMX partial update (falls back to normal links).
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var string $route
 * @var array $query
 * @var array|null $args
 * @var string|null $target  CSS selector to swap (default #main)
 */

$args ??= [];
$target ??= '#main';
$pages = $page->pages();
$from = $page->total === 0 ? 0 : ($page->page - 1) * $page->perPage + 1;
$to = min($page->total, $page->page * $page->perPage);
$link = static function (int $p, string $label, bool $current = false) use ($h, $route, $args, $query, $target): string {
    if ($current) {
        return '<span class="current" aria-current="page">' . $label . '</span>';
    }
    $url = $h->url($route, $args, array_filter(['page' => $p] + $query, static fn($v) => $v !== null && $v !== ''));
    return '<a href="' . $h->e($url) . '" hx-get="' . $h->e($url) . '" hx-target="' . $h->e($target) . '" hx-select="' . $h->e($target) . '" hx-swap="outerHTML" hx-push-url="true">' . $label . '</a>';
};
?>
<div class="pager">
    <span><?= $h->t('{from}–{to} of {total}', ['from' => $from, 'to' => $to, 'total' => $page->total]) ?></span>
    <?php if ($pages > 1): ?>
        <nav class="pager__links" aria-label="<?= $h->t('Pagination') ?>">
            <?php if ($page->page > 1): ?><?= $link($page->page - 1, '‹') ?><?php endif ?>
            <?php
            $start = max(1, $page->page - 2);
            $end = min($pages, $page->page + 2);
            if ($start > 1) { echo $link(1, '1'); if ($start > 2) { echo '<span>…</span>'; } }
            for ($i = $start; $i <= $end; $i++) { echo $link($i, (string) $i, $i === $page->page); }
            if ($end < $pages) { if ($end < $pages - 1) { echo '<span>…</span>'; } echo $link($pages, (string) $pages); }
            ?>
            <?php if ($page->page < $pages): ?><?= $link($page->page + 1, '›') ?><?php endif ?>
        </nav>
    <?php endif ?>
</div>
