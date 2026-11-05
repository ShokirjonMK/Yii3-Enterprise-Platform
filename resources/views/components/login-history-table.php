<?php

declare(strict_types=1);

/**
 * Sign-in history table — shared by Account → Login history and Admin → Users → Login history.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $query current filters (for sort links)
 * @var string $route list route name
 * @var bool $showUser
 * @var string|null $userRoute route of the user's admin page (admin only)
 */

use App\Platform\Identity\Application\LoginHistory;
use App\Platform\Identity\Mfa\MfaMethodType;

$userRoute ??= null;
$methodLabel = static function (?string $m) use ($h): string {
    return match (true) {
        $m === null || $m === '' => '—',
        $m === 'password', $m === 'api_password' => $h->tr($m === 'password' ? 'Password' : 'Password (API)'),
        $m === 'mfa' => $h->tr('Second factor'),
        str_starts_with($m, 'sso') => 'SSO ' . trim(substr($m, 3), ':'),
        default => $m,
    };
};
$factorLabel = static function (?string $f) use ($h): string {
    return match ($f) {
        null, '' => '—',
        'trusted_device' => $h->tr('Remembered device'),
        'recovery' => $h->tr('Recovery code'),
        default => $h->tr(MfaMethodType::label($f)),
    };
};
?>
<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
        <th><?= $h->sortLink($h->tr('Time'), 'created_at', $page->sort, $query, $route) ?></th>
        <?php if ($showUser): ?><th><?= $h->sortLink($h->tr('User'), 'username', $page->sort, $query, $route) ?></th><?php endif ?>
        <th><?= $h->sortLink($h->tr('Result'), 'success', $page->sort, $query, $route) ?></th>
        <th><?= $h->t('Method') ?></th>
        <th><?= $h->t('Second factor') ?></th>
        <th><?= $h->sortLink($h->tr('IP'), 'ip', $page->sort, $query, $route) ?></th>
        <th><?= $h->t('Device') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($page->items as $a): ?>
        <tr>
            <td class="nowrap small"><?= $h->datetime($a['created_at'], 'Y-m-d H:i:s') ?></td>
            <?php if ($showUser): ?>
                <td>
                    <?php if ($a['user_id'] !== null && $userRoute !== null): ?>
                        <a href="<?= $h->url($userRoute, ['id' => $a['user_id']]) ?>"><?= $h->e($a['username']) ?></a>
                    <?php else: ?>
                        <span class="mono"><?= $h->e($a['username']) ?></span>
                    <?php endif ?>
                    <?php if ($a['full_name']): ?><span class="small muted d-block"><?= $h->e($a['full_name']) ?></span><?php endif ?>
                </td>
            <?php endif ?>
            <td>
                <?= $a['success'] ? $h->badge('ok', $h->tr('success')) : $h->badge('error', $h->tr('failed')) ?>
                <?php if (!$a['success']): ?><span class="small muted d-block"><?= $h->t(LoginHistory::REASONS[$a['reason']] ?? (string) $a['reason']) ?></span><?php endif ?>
            </td>
            <td class="small"><?= $h->e($methodLabel($a['method'])) ?></td>
            <td class="small"><?= $h->e($factorLabel($a['mfa_method'])) ?></td>
            <td class="mono small"><?= $h->e($a['ip'] ?? '—') ?></td>
            <td class="small" title="<?= $h->e($a['user_agent'] ?? '') ?>"><?= $h->e(LoginHistory::device($a['user_agent'])) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table></div>
