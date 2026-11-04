<?php

declare(strict_types=1);

namespace App\Admin\Action\Settings;

use App\Platform\Access\AccessService;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
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
 * 21 · System Settings — grouped forms with value source (env/tenant/global/default), write-only secrets,
 * history & rollback per key. Critical and secret keys are changed only through the step-up protected
 * "secure" route (superadmin for critical keys is enforced by SettingsService).
 */
final class SettingsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SettingsService $settings,
        private readonly AccessService $access,
        private readonly ConnectionInterface $db,
        private readonly CurrentUser $user,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request->getQueryParams(), [], [], 200);
    }

    /** Non-critical, non-secret keys of one group (batch form). */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        [$scope, $tenant] = $this->scope($in);
        $group = (string) ($in['group'] ?? '');
        $values = (array) ($in['settings'] ?? []);
        $present = array_keys((array) ($in['present'] ?? []));
        $errors = [];
        $changed = 0;
        foreach ($present as $key) {
            $key = (string) $key;
            $def = $this->settings->definitions()[$key] ?? null;
            if ($def === null || ($def['group'] ?? 'Other') !== $group) {
                continue;
            }
            if (($def['critical'] ?? false) || $def['type'] === 'secret') {
                $errors[$key][] = 'Critical and secret settings are changed individually (re-authentication required).';
                continue;
            }
            if (!in_array($scope, $def['scopes'] ?? ['global'], true)) {
                continue;
            }
            $raw = $def['type'] === 'bool' ? (isset($values[$key]) && $values[$key] === '1') : ($values[$key] ?? '');
            if ($this->same($key, $def, $raw, $tenant)) {
                continue;
            }
            try {
                $this->settings->set($this->actor(), $key, $raw, $scope, $tenant, (string) ($in['comment'] ?? ''));
                $changed++;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $msgs) {
                    $errors[$key] = [...($errors[$key] ?? []), ...$msgs];
                }
            }
        }
        if ($errors !== []) {
            return $this->renderIndex(['group' => $group, 'tenant' => $tenant] + ($scope === 'tenant' ? ['scope' => 'tenant'] : []), $errors, $values, 422);
        }
        $this->responder->success($changed === 0 ? 'No changes.' : sprintf('%d setting(s) saved.', $changed));
        return $this->responder->redirect('admin.settings', [], array_filter(['group' => $group, 'scope' => $scope === 'tenant' ? 'tenant' : null, 'tenant' => $tenant]));
    }

    /** One critical or secret key (route protected by RequireRecentAuth). */
    public function secure(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        $key = (string) ($in['key'] ?? '');
        $def = $this->settings->definition($key);
        [$scope, $tenant] = $this->scope($in);
        $group = (string) ($def['group'] ?? 'Other');
        try {
            if ($def['type'] === 'secret') {
                if (($in['clear'] ?? '') === '1') {
                    $this->settings->set($this->actor(), $key, null, $scope, $tenant, (string) ($in['comment'] ?? 'cleared'));
                    $this->responder->success('Secret cleared.');
                } elseif ((string) ($in['value'] ?? '') !== '') {
                    $this->settings->set($this->actor(), $key, (string) $in['value'], $scope, $tenant, (string) ($in['comment'] ?? ''));
                    $this->responder->success('Secret updated.');
                } else {
                    $this->responder->info('Secret unchanged (empty input).');
                }
            } else {
                $raw = $def['type'] === 'bool' ? (($in['value'] ?? '') === '1') : ($in['value'] ?? '');
                $this->settings->set($this->actor(), $key, $raw, $scope, $tenant, (string) ($in['comment'] ?? ''));
                $this->responder->success('Setting saved.');
            }
        } catch (ValidationException $e) {
            return $this->renderIndex(['group' => $group, 'tenant' => $tenant] + ($scope === 'tenant' ? ['scope' => 'tenant'] : []), $e->errors() + [$key => [$e->getMessage()]], [], 422);
        } catch (AccessDeniedException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.settings', [], array_filter(['group' => $group, 'scope' => $scope === 'tenant' ? 'tenant' : null, 'tenant' => $tenant]));
    }

    public function history(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $key = (string) $route->getArgument('key');
        $def = $this->settings->definition($key);
        [$scope, $tenant] = $this->scope($request->getQueryParams());
        return $this->responder->render('admin/settings/history', [
            'key' => $key, 'def' => $def, 'scope' => $scope, 'tenant' => $tenant,
            'versions' => $this->settings->history($key, $scope, $tenant),
            'source' => $this->settings->source($key, $tenant),
            'current' => $def['type'] === 'secret' ? null : $this->settings->get($key, $tenant),
        ]);
    }

    public function rollback(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $key = (string) $route->getArgument('key');
        $this->settings->definition($key);
        $in = (array) $request->getParsedBody();
        [$scope, $tenant] = $this->scope($in);
        try {
            $this->settings->rollback($this->actor(), $key, (int) ($in['version'] ?? 0), $scope, $tenant);
            $this->responder->success(sprintf('Rolled back to version %d.', (int) ($in['version'] ?? 0)));
        } catch (ValidationException | AccessDeniedException | NotFoundException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.settings.history', ['key' => $key], array_filter(['scope' => $scope === 'tenant' ? 'tenant' : null, 'tenant' => $tenant]));
    }

    public function reset(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $key = (string) $route->getArgument('key');
        $def = $this->settings->definition($key);
        [$scope, $tenant] = $this->scope((array) $request->getParsedBody());
        try {
            $this->settings->reset($this->actor(), $key, $scope, $tenant);
            $this->responder->success('Override removed; the inherited/default value applies.');
        } catch (AccessDeniedException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.settings', [], array_filter(['group' => $def['group'] ?? null, 'scope' => $scope === 'tenant' ? 'tenant' : null, 'tenant' => $tenant]));
    }

    private function renderIndex(array $q, array $errors, array $submitted, int $status): ResponseInterface
    {
        [$scope, $tenant] = $this->scope($q);
        $grouped = $this->settings->grouped($tenant);
        $groups = array_keys($grouped);
        $group = in_array($q['group'] ?? '', $groups, true) ? (string) $q['group'] : ($groups[0] ?? '');
        return $this->responder->render('admin/settings/index', [
            'groups' => $groups, 'group' => $group, 'items' => $grouped[$group] ?? [],
            'scope' => $scope, 'tenant' => $tenant, 'tenants' => (new Query($this->db))->select(['id', 'code', 'name'])->from('tenants')->orderBy('code')->all(),
            'errors' => $errors, 'submitted' => $submitted,
            'canManage' => $this->access->can($this->actor(), 'settings.manage'),
            'isSuperadmin' => $this->access->isSuperadmin($this->actor()),
        ], status: $status);
    }

    /** @return array{0:string, 1:?string} scope, tenant id */
    private function scope(array $in): array
    {
        $tenant = (string) ($in['tenant'] ?? '');
        if (($in['scope'] ?? '') === 'tenant' && Uuid::isValid($tenant) && (new Query($this->db))->from('tenants')->where(['id' => $tenant])->exists()) {
            return ['tenant', $tenant];
        }
        return ['global', null];
    }

    private function same(string $key, array $def, mixed $raw, ?string $tenant): bool
    {
        $current = $this->settings->get($key, $tenant);
        return match ($def['type']) {
            'bool' => (bool) $current === (bool) $raw,
            'int' => is_numeric($raw) && (int) $raw === (int) $current && $current !== null,
            'json' => json_decode((string) $raw, true) === $current && json_last_error() === JSON_ERROR_NONE,
            default => trim((string) $raw) === (string) $current,
        };
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
