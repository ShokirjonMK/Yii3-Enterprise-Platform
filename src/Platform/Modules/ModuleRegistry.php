<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Audit\AuditLogger;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Settings\ConfigRevision;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Database\Sql;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\RouteCollectionInterface;

/**
 * Business module registry = manifests (src/Modules/<Name>/module.php) + installed state (business_modules)
 * + feature flag "module.<code>" (the runtime on/off switch evaluated by the module's routes/services).
 *
 * Manifest convention (array): code, name, version, description, flag (default module.<code>), admin_route,
 * web_route, api_prefix, permissions (list), jobs, topics, consumer_groups.
 */
final class ModuleRegistry
{
    private readonly string $directory;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FeatureFlagService $flags,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly RbacCatalog $catalog,
        private readonly ConfigRevision $revision,
        private readonly ?RouteCollectionInterface $routes = null,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? dirname(__DIR__, 2) . '/Modules';
    }

    /** @return array<string, array<string,mixed>> code => manifest (+ '_dir') */
    public function manifests(): array
    {
        $out = [];
        foreach (glob($this->directory . '/*/module.php') ?: [] as $file) {
            try {
                $m = require $file;
            } catch (Throwable) {
                continue;
            }
            if (!is_array($m)) {
                continue;
            }
            $dir = basename(dirname($file));
            $code = (string) ($m['code'] ?? strtolower($dir));
            if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code) !== 1) {
                continue;
            }
            $out[$code] = $m + ['code' => $code, 'name' => $dir, 'version' => '0.0.0', 'description' => '', 'flag' => 'module.' . $code, '_dir' => $dir];
            $out[$code]['_dir'] = $dir;
        }
        ksort($out);
        return $out;
    }

    /** @return list<array<string,mixed>> registry rows for the admin list */
    public function all(): array
    {
        $manifests = $this->manifests();
        $installed = (new Query($this->db))->from('business_modules')->indexBy('code')->all();
        $flags = $this->flags->all();
        $routeStats = $this->routeStats($manifests);
        $codes = array_unique([...array_keys($manifests), ...array_keys($installed)]);
        sort($codes);
        $out = [];
        foreach ($codes as $code) {
            $m = $manifests[$code] ?? null;
            $row = $installed[$code] ?? null;
            $flagCode = (string) ($m['flag'] ?? 'module.' . $code);
            $flag = $flags[$flagCode] ?? null;
            $perms = array_values(array_unique([
                ...((array) ($m['permissions'] ?? [])),
                ...array_values(array_filter(array_keys($this->catalog->permissions()), static fn($p) => str_starts_with($p, $code . '.'))),
            ]));
            $out[] = [
                'code' => $code,
                'name' => (string) ($m['name'] ?? $row['name'] ?? $code),
                'version' => (string) ($m['version'] ?? $row['version'] ?? ''),
                'installed_version' => $row['version'] ?? null,
                'description' => (string) ($m['description'] ?? ''),
                'manifest' => $m,
                'installed' => $row !== null,
                'installed_at' => $row['installed_at'] ?? null,
                'enabled' => $row !== null ? Sql::bool($row['enabled']) : false,
                'flag' => $flagCode,
                'flag_exists' => $flag !== null,
                'flag_enabled' => $this->flags->isEnabled($flagCode),
                'flag_status' => $flag['status'] ?? null,
                'admin_route' => $m['admin_route'] ?? null,
                'routes' => $routeStats[$code] ?? 0,
                'permissions' => $perms,
                'missing' => $m === null,
            ];
        }
        return $out;
    }

    public function get(string $code): array
    {
        foreach ($this->all() as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }
        throw new NotFoundException('Module not found.');
    }

    /** Registers manifests in business_modules (new rows start disabled; existing rows get the manifest name/version). */
    public function sync(string $actorId): int
    {
        $this->access->assert($actorId, 'modules.manage');
        $n = 0;
        $this->db->transaction(function () use (&$n, $actorId): void {
            foreach ($this->manifests() as $code => $m) {
                $n += $this->db->createCommand(
                    "INSERT INTO business_modules (code, name, version, enabled) VALUES (:c, :n, :v, false)
                     ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, version = EXCLUDED.version, updated_at = now()
                     WHERE business_modules.name IS DISTINCT FROM EXCLUDED.name OR business_modules.version IS DISTINCT FROM EXCLUDED.version",
                    [':c' => $code, ':n' => mb_substr((string) $m['name'], 0, 255), ':v' => mb_substr((string) $m['version'], 0, 32)],
                )->execute();
            }
            if ($n > 0) {
                $this->audit->record('module.registry_synced', 'business_module', null, null, ['changed' => $n], category: 'config', severity: 'notice', actorId: $actorId);
            }
        });
        return $n;
    }

    /**
     * Turns a module on/off: feature flag module.<code> (through FeatureFlagService — which additionally requires
     * settings.manage and validates flag dependencies) + business_modules.enabled.
     */
    public function setEnabled(string $actorId, string $code, bool $enabled): void
    {
        $this->access->assert($actorId, 'modules.manage');
        $manifests = $this->manifests();
        $m = $manifests[$code] ?? null;
        $row = (new Query($this->db))->from('business_modules')->where(['code' => $code])->one();
        if ($m === null && $row === null) {
            throw new NotFoundException('Module not found.');
        }
        if ($enabled && $m === null) {
            throw new NotFoundException('The module code is not present in this build (no manifest) and cannot be enabled.');
        }
        $flagCode = (string) ($m['flag'] ?? 'module.' . $code);
        if (!isset($this->flags->all()[$flagCode])) {
            // register the module's flag (disabled) so that it can be switched through FeatureFlagService
            $this->db->createCommand(
                "INSERT INTO feature_flags (code, name, description, enabled, status) VALUES (:c, :n, :d, false, 'disabled') ON CONFLICT (code) DO NOTHING",
                [':c' => $flagCode, ':n' => mb_substr(($m['name'] ?? $code) . ' module', 0, 255), ':d' => 'Business module switch (registered by Admin → Business Modules).'],
            )->execute();
            $this->revision->bump();
        }
        $enabled ? $this->flags->enable($actorId, $flagCode) : $this->flags->disable($actorId, $flagCode);
        $this->db->transaction(function () use ($code, $m, $row, $enabled, $actorId): void {
            if ($row === null) {
                $this->db->createCommand()->insert('business_modules', ['code' => $code, 'name' => mb_substr((string) $m['name'], 0, 255), 'version' => mb_substr((string) $m['version'], 0, 32), 'enabled' => $enabled])->execute();
            } else {
                $this->db->createCommand()->update('business_modules', ['enabled' => $enabled, 'updated_at' => new \Yiisoft\Db\Expression\Expression('now()')], ['code' => $code])->execute();
            }
            $this->audit->record(
                $enabled ? 'module.enabled' : 'module.disabled',
                'business_module',
                $code,
                ['enabled' => $row !== null && Sql::bool($row['enabled'])],
                ['enabled' => $enabled],
                category: 'config',
                severity: 'notice',
                actorId: $actorId,
            );
        });
    }

    /**
     * Routes per module: route name contains the module code as a segment, or the action lives in App\Modules\<Dir>\.
     *
     * @param array<string, array<string,mixed>> $manifests
     * @return array<string,int>
     */
    private function routeStats(array $manifests): array
    {
        if ($this->routes === null) {
            return [];
        }
        $stats = [];
        try {
            foreach ($this->routes->getRoutes() as $route) {
                $name = (string) $route->getData('name');
                $mw = $route->getData('enabledMiddlewares');
                $action = end($mw);
                $class = is_array($action) ? (string) ($action[0] ?? $action['class'] ?? '') : (is_string($action) ? $action : '');
                foreach ($manifests as $code => $m) {
                    $segments = explode('.', $name);
                    if (in_array($code, $segments, true) || str_starts_with($class, 'App\\Modules\\' . $m['_dir'] . '\\')) {
                        $stats[$code] = ($stats[$code] ?? 0) + 1;
                    }
                }
            }
        } catch (Throwable) {
        }
        return $stats;
    }
}
