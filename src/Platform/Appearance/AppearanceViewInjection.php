<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Platform\Identity\Domain\Identity;
use Throwable;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\LayoutParametersInjectionInterface;

/**
 * Gives every layout the active theme: $theme = ['code' => 'ocean', 'href' => '/appearance/ocean.css?v=<hash>',
 * 'preview' => bool, 'name' => '…']. The "default" theme adds no stylesheet (its tokens are app.css itself).
 *
 * Author: ShokirjonMK
 */
final class AppearanceViewInjection implements LayoutParametersInjectionInterface
{
    public const PREVIEW = 'appearance.preview';

    public function __construct(
        private readonly ThemeService $themes,
        private readonly CurrentUser $currentUser,
        private readonly SessionInterface $session,
    ) {}

    public function getLayoutParameters(): array
    {
        try {
            $identity = $this->currentUser->getIdentity();
            $user = $identity instanceof Identity ? $identity->user : null;
            $preview = null;
            if ($user !== null && $this->session->isActive()) {
                $p = $this->session->get(self::PREVIEW);
                $preview = is_string($p) ? $p : null;
            }
            $code = $this->themes->resolve($user?->tenantId, $user?->id, $preview);
            $theme = $this->themes->registry()->get($code);
            return ['theme' => [
                'code' => $code,
                'name' => (string) ($theme['name'] ?? $code),
                'href' => $code === ThemeRegistry::DEFAULT || $theme === null ? null : '/appearance/' . $code . '.css?v=' . $theme['hash'],
                'preview' => $preview !== null && $preview === $code,
            ]];
        } catch (Throwable) {
            return ['theme' => ['code' => ThemeRegistry::DEFAULT, 'name' => 'Default', 'href' => null, 'preview' => false]];
        }
    }
}
