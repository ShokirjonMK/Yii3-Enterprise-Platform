<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{state:string, failures:int, retry_in:int, available:bool} $breaker
 */
$tone = ['closed' => 'ok', 'half_open' => 'warn', 'open' => 'bad'][$breaker['state']] ?? 'muted';
$label = ['closed' => 'Circuit closed', 'half_open' => 'Circuit half-open', 'open' => 'Circuit open'][$breaker['state']] ?? $breaker['state'];
?>
<span class="badge badge--<?= $tone ?>" title="<?= $h->t('{n} recent failures', ['n' => $breaker['failures']]) ?>"><?= $h->t($label) ?><?php if ($breaker['state'] === 'open'): ?> · <?= (int) $breaker['retry_in'] ?>s<?php endif ?></span>
