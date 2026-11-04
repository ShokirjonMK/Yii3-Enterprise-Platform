<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\View;

use App\Platform\Access\AccessService;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Infrastructure\Http\Htmx;
use Throwable;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;
use Yiisoft\Yii\View\Renderer\LayoutParametersInjectionInterface;

/**
 * Parameters available in every view and layout:
 *   $identity (?Identity), $can (callable(string): bool), $isHtmx (bool), $flashes (array), $platform (array)
 */
final class PlatformViewInjection implements CommonParametersInjectionInterface, LayoutParametersInjectionInterface
{
    /** @var array<string,bool> */
    private array $permissionCache = [];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly AccessService $access,
        private readonly RequestProviderInterface $requestProvider,
        private readonly FlashInterface $flash,
        private readonly array $platform,
        private readonly \App\Platform\FeatureFlags\FeatureFlagService $flags,
    ) {}

    public function getCommonParameters(): array
    {
        $identity = null;
        try {
            $i = $this->currentUser->getIdentity();
            $identity = $i instanceof Identity ? $i : null;
        } catch (Throwable) {
        }
        $request = null;
        try {
            $request = $this->requestProvider->get();
        } catch (Throwable) {
        }
        return [
            'identity' => $identity,
            'can' => function (string $permission) use ($identity): bool {
                if ($identity === null) {
                    return false;
                }
                return $this->permissionCache[$permission] ??= $this->access->can($identity->user->id, $permission);
            },
            // $feature('admin.kafka') → false when the optional feature behind that menu route is switched off
            'feature' => function (string $route) use ($identity): bool {
                $flag = \App\Platform\FeatureFlags\FeatureRegistry::flagForMenu($route);
                return $flag === null || $this->flags->isEnabled($flag, $identity?->user->tenantId, $identity?->user->id);
            },
            'isHtmx' => Htmx::isPartial($request),
            'request' => $request,
            'platform' => [
                'name' => $this->platform['name'],
                'version' => $this->platform['version'],
                'locales' => $this->platform['locales'],
                'author' => $this->platform['author'] ?? 'ShokirjonMK',
                'authorUrl' => $this->platform['authorUrl'] ?? 'https://github.com/ShokirjonMK',
            ],
        ];
    }

    public function getLayoutParameters(): array
    {
        return ['flashes' => $this->flash->getAll()];
    }
}
