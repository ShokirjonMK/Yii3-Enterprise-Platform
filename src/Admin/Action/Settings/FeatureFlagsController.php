<?php

declare(strict_types=1);

namespace App\Admin\Action\Settings;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 21 · System Settings → Feature flags: status badges, enable/disable (dependency problems shown), rollout %,
 * tenant allowlist, schedule window and per-tenant overrides. All rules live in FeatureFlagService.
 */
final class FeatureFlagsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly FeatureFlagService $flags,
        private readonly ConnectionInterface $db,
        private readonly CurrentUser $user,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $rows = [];
        foreach ($this->flags->all() as $code => $flag) {
            if ($q !== '' && !str_contains($code, strtolower($q)) && !str_contains(mb_strtolower((string) $flag['name']), mb_strtolower($q))) {
                continue;
            }
            $rows[] = $flag + ['effective' => $this->flags->isEnabled($code), 'problems' => $this->flags->dependencyProblems($flag)];
        }
        return $this->responder->render('admin/settings/flags', ['flags' => $rows, 'q' => $q]);
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        return $this->renderShow((string) $route->getArgument('code'), [], 200);
    }

    public function enable(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        try {
            $this->flags->enable($this->actor(), $code);
            $this->responder->success('Feature flag enabled.');
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
    }

    public function disable(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        try {
            $this->flags->disable($this->actor(), $code);
            $this->responder->success('Feature flag disabled.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
    }

    public function configure(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $in = (array) $request->getParsedBody();
        $input = [
            'rollout_percentage' => (string) ($in['rollout_percentage'] ?? '100'),
            'tenant_allowlist' => (string) ($in['tenant_allowlist'] ?? ''),
            'scheduled_enable_at' => (string) ($in['scheduled_enable_at'] ?? ''),
            'scheduled_disable_at' => (string) ($in['scheduled_disable_at'] ?? ''),
        ];
        if (($in['status'] ?? '') !== '' && ($in['status'] ?? '') !== ($this->flags->get($code)['status'])) {
            $input['status'] = (string) $in['status'];
        }
        foreach (['scheduled_enable_at', 'scheduled_disable_at'] as $f) {
            if ($input[$f] !== '') {
                // datetime-local carries no zone: interpret in the platform zone
                $ts = strtotime($input[$f] . ' Asia/Tashkent');
                $input[$f] = $ts === false ? $input[$f] : date(DATE_ATOM, $ts);
            }
        }
        try {
            $this->flags->configure($this->actor(), $code, $input);
        } catch (ValidationException $e) {
            return $this->renderShow($code, $e->errors(), 422);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
        }
        $this->responder->success('Rollout configuration saved.');
        return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
    }

    public function override(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $in = (array) $request->getParsedBody();
        $tenant = (string) ($in['tenant_id'] ?? '');
        if (!Uuid::isValid($tenant) || !(new Query($this->db))->from('tenants')->where(['id' => $tenant])->exists()) {
            $this->responder->error('Unknown tenant.');
            return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
        }
        $value = match ((string) ($in['value'] ?? '')) { 'on' => true, 'off' => false, default => null };
        $this->flags->setTenantOverride($this->actor(), $code, $tenant, $value);
        $this->responder->success($value === null ? 'Tenant override removed.' : 'Tenant override saved.');
        return $this->responder->redirect('admin.settings.flags.show', ['code' => $code]);
    }

    private function renderShow(string $code, array $errors, int $status): ResponseInterface
    {
        $flag = $this->flags->get($code);
        $tenants = (new Query($this->db))->select(['id', 'code', 'name'])->from('tenants')->orderBy('code')->all();
        return $this->responder->render('admin/settings/flag', [
            'flag' => $flag, 'effective' => $this->flags->isEnabled($code), 'problems' => $this->flags->dependencyProblems($flag),
            'tenants' => $tenants, 'tenantNames' => array_column($tenants, 'code', 'id'), 'errors' => $errors,
            'statuses' => FeatureFlagService::STATUSES,
        ], status: $status);
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
