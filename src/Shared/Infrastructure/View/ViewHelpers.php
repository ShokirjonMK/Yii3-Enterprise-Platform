<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\View;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Translator\TranslatorInterface;

/**
 * Small, explicit helper set available in every view as `$h`.
 * All output helpers HTML-encode their input (XSS protection by default).
 */
final class ViewHelpers
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CurrentRoute $currentRoute,
        private readonly ?\Yiisoft\Aliases\Aliases $aliases = null,
    ) {}

    /**
     * Absolute path of a view that lives in another folder, for `$this->render($h->view('@admin/Users/views/_field'), …)`.
     * Aliases: @admin (src/Admin), @web (src/Web), @api (src/Api), @modules (src/Modules), @views (resources/views).
     */
    public function view(string $alias): string
    {
        return $this->aliases?->get($alias) ?? $alias;
    }

    /** Encode for HTML text/attribute context. */
    public function e(mixed $value): string
    {
        return Html::encode($value === null ? '' : (is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value)));
    }

    /** Translate (returns encoded text). */
    public function t(string $message, array $params = [], string $category = 'app'): string
    {
        return Html::encode($this->translator->translate($message, $params, $category));
    }

    /** Translate (raw — only for use inside attribute builders that encode themselves). */
    public function tr(string $message, array $params = [], string $category = 'app'): string
    {
        return $this->translator->translate($message, $params, $category);
    }

    public function url(string $name, array $arguments = [], array $query = []): string
    {
        return $this->urlGenerator->generate($name, $arguments, $query);
    }

    /** URL or null when the route is not registered (optional modules/sections). */
    public function urlOrNull(string $name, array $arguments = [], array $query = []): ?string
    {
        try {
            return $this->urlGenerator->generate($name, $arguments, $query);
        } catch (\Yiisoft\Router\RouteNotFoundException) {
            return null;
        }
    }

    public function isRoute(string ...$prefixes): bool
    {
        $name = $this->currentRoute->getName() ?? '';
        foreach ($prefixes as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix . '.')) {
                return true;
            }
        }
        return false;
    }

    public function locale(): string
    {
        return $this->translator->getLocale();
    }

    public function datetime(null|string|DateTimeInterface $value, string $format = 'Y-m-d H:i', string $tz = 'Asia/Tashkent'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $dt = $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : new DateTimeImmutable($value);
        return Html::encode($dt->setTimezone(new DateTimeZone($tz))->format($format));
    }

    public function ago(null|string|DateTimeInterface $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $dt = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value);
        $diff = time() - $dt->getTimestamp();
        return Html::encode(match (true) {
            $diff < 60 => $this->tr('{n}s ago', ['n' => max(0, $diff)]),
            $diff < 3600 => $this->tr('{n}m ago', ['n' => intdiv($diff, 60)]),
            $diff < 86400 => $this->tr('{n}h ago', ['n' => intdiv($diff, 3600)]),
            default => $this->tr('{n}d ago', ['n' => intdiv($diff, 86400)]),
        });
    }

    public function bytes(null|int|float|string $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return '—';
        }
        $b = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $b, $units[$i]);
    }

    /** Status pill with semantic color. */
    public function badge(?string $status, ?string $label = null): string
    {
        $status ??= 'unknown';
        $tone = match ($status) {
            'active', 'enabled', 'healthy', 'succeeded', 'success', 'published', 'sent', 'delivered', 'approved', 'passed', 'resolved', 'closed', 'idle', 'up', 'ok', 'done', 'completed' => 'ok',
            'degraded', 'pending', 'queued', 'running', 'busy', 'investigating', 'identified', 'monitoring', 'requested', 'reserved', 'sending', 'warning', 'in_progress', 'review' => 'warn',
            'failed', 'blocked', 'dead', 'rejected', 'denied', 'failure', 'critical', 'down', 'lost', 'sev1', 'sev2', 'open', 'error' => 'bad',
            'maintenance', 'paused', 'stopping', 'rolled_back' => 'info',
            default => 'muted',
        };
        return '<span class="badge badge--' . $tone . '">' . Html::encode($label ?? $this->tr($status)) . '</span>';
    }

    public function icon(string $name, string $class = ''): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', $name);
        return '<svg class="icon ' . Html::encode($class) . '" aria-hidden="true"><use href="/static/icons.svg?v=1#' . $name . '"></use></svg>';
    }

    /**
     * Sortable column header link (keeps current query, toggles direction).
     */
    public function sortLink(string $label, string $key, string $current, array $query, string $route, array $args = []): string
    {
        $isCurrent = ltrim($current, '-') === $key;
        $desc = str_starts_with($current, '-');
        $next = $isCurrent && !$desc ? '-' . $key : $key;
        $query['sort'] = $next;
        unset($query['page']);
        $aria = $isCurrent ? ' aria-sort="' . ($desc ? 'descending' : 'ascending') . '"' : '';
        $arrow = $isCurrent ? ($desc ? ' ↓' : ' ↑') : '';
        return '<a href="' . Html::encode($this->urlGenerator->generate($route, $args, $query)) . '"' . $aria . '>' . Html::encode($label) . $arrow . '</a>';
    }

    public function json(mixed $value): string
    {
        return Html::encode((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
