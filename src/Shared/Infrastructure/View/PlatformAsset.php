<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\View;

use Yiisoft\Assets\AssetBundle;

/**
 * Admin/Web UI assets. All scripts are external files (CSP script-src 'self'); htmx is vendored
 * locally (no CDN) for air-gapped government deployments.
 */
final class PlatformAsset extends AssetBundle
{
    public ?string $basePath = '@assets';
    public ?string $baseUrl = '@assetsUrl';
    public ?string $sourcePath = '@assetsSource';

    public array $css = [
        'admin/app.css',
    ];

    public array $js = [
        ['vendor/htmx.min.js', 'defer' => true],
        ['admin/app.js', 'defer' => true],
        ['admin/mfa.js', 'defer' => true],
    ];

    public array $publishOptions = [
        'only' => ['admin/*', 'vendor/*'],
    ];
}
