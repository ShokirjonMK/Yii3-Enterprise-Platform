<?php

declare(strict_types=1);

/**
 * Inline SVG sparkline (no inline styles; .sparkline CSS).
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<float> $values
 * @var string $label
 */

$values = array_values(array_map('floatval', $values));
if (count($values) < 2) {
    return;
}
$max = max($values);
$min = min(0.0, min($values));
$span = $max - $min ?: 1.0;
$n = count($values) - 1;
$line = '';
foreach ($values as $i => $v) {
    $x = round($i / $n * 300, 1);
    $y = round(46 - (($v - $min) / $span) * 42, 1);
    $line .= ($i === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
}
$area = trim($line) . ' L300 48 L0 48 Z';
?>
<svg class="sparkline" viewBox="0 0 300 48" preserveAspectRatio="none" role="img" aria-label="<?= $h->e($label) ?>"><path class="area" d="<?= $h->e($area) ?>"/><path d="<?= $h->e(trim($line)) ?>"/></svg>
