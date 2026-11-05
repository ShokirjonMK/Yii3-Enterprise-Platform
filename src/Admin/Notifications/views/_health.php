<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $code
 * @var array{status:string, details:array} $result
 */
?>
<span data-health="<?= $h->e($result['status']) ?>"><?= $h->badge($result['status'], $result['status'] === 'unconfigured' ? $h->tr('Not configured') : null) ?></span>
<?php foreach ($result['details'] as $k => $v): ?><div class="small muted"><?= $h->e($k) ?>: <?= $h->e(is_scalar($v) ? (string) $v : json_encode($v)) ?></div><?php endforeach ?>
