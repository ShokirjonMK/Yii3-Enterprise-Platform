<?php

declare(strict_types=1);

namespace App\Admin\DevTools;

use App\Environment;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Queue\HandlerRegistry;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Predis\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\RouteCollectionInterface;

/**
 * 22 · Developer Tools — read-only engineering views. Available ONLY when APP_ENV != prod AND flag
 * devtools.gii is enabled (otherwise 404, the section does not exist), and the user holds devtools.use.
 * Nothing here exposes secrets: the environment summary is built from an explicit allowlist, not from getenv().
 */
final class DevToolsController
{
    public const TABS = ['routes' => 'Routes', 'jobs' => 'Job types', 'consumers' => 'Consumer groups', 'commands' => 'Console commands',
        'environment' => 'Environment', 'extensions' => 'PHP extensions', 'migrations' => 'Migrations'];

    public function __construct(
        private readonly Responder $responder,
        private readonly FeatureFlagService $flags,
        private readonly RouteCollectionInterface $routes,
        private readonly HandlerRegistry $handlers,
        private readonly KafkaConsumerRunner $consumers,
        private readonly ConnectionInterface $db,
        private readonly ClientInterface $redis,
    ) {}

    public static function available(FeatureFlagService $flags): bool
    {
        return !Environment::isProd() && $flags->isEnabled('devtools.gii');
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (!self::available($this->flags)) {
            throw new NotFoundException('Page not found.');
        }
        $q = $request->getQueryParams();
        $tab = isset(self::TABS[$q['tab'] ?? '']) ? (string) $q['tab'] : 'routes';
        $filter = trim((string) ($q['q'] ?? ''));
        $data = match ($tab) {
            'routes' => $this->routeList($filter),
            'jobs' => $this->jobTypes(),
            'consumers' => $this->consumers->groups(),
            'commands' => $this->commands(),
            'environment' => $this->environment(),
            'extensions' => $this->extensions(),
            'migrations' => $this->db->createCommand('SELECT name, apply_time FROM migration ORDER BY id DESC')->queryAll(),
        };
        return $this->responder->render('@admin/DevTools/views/index', ['tab' => $tab, 'data' => $data, 'filter' => $filter, 'tabs' => self::TABS]);
    }

    /** @return list<array<string,mixed>> */
    private function routeList(string $filter): array
    {
        $out = [];
        foreach ($this->routes->getRoutes() as $route) {
            $name = (string) $route->getData('name');
            $pattern = (string) $route->getData('pattern');
            if ($filter !== '' && !str_contains($name, $filter) && !str_contains($pattern, $filter)) {
                continue;
            }
            $perms = [];
            $reauth = false;
            $action = '';
            $mws = $route->getData('enabledMiddlewares');
            foreach ($mws as $i => $mw) {
                if (is_array($mw) && ($mw['class'] ?? null) === RequirePermission::class) {
                    $perms = [...$perms, ...((array) ($mw['__construct()']['anyOf'] ?? []))];
                } elseif ($mw === RequireRecentAuth::class) {
                    $reauth = true;
                }
                if ($i === array_key_last($mws)) {
                    $action = is_array($mw) && isset($mw[0], $mw[1]) && is_string($mw[0]) ? $mw[0] . '::' . $mw[1] : (is_string($mw) ? $mw : '(closure)');
                }
            }
            $out[] = ['name' => $name, 'methods' => implode(', ', $route->getData('methods')), 'pattern' => $pattern, 'permissions' => array_values(array_unique($perms)), 'reauth' => $reauth, 'action' => $action];
        }
        usort($out, static fn($a, $b) => strcmp($a['pattern'], $b['pattern']));
        return $out;
    }

    /** @return list<array{type:string, queue:string, handler:string}> */
    private function jobTypes(): array
    {
        $map = require dirname(__DIR__, 3) . '/config/common/jobs.php';
        $out = [];
        foreach ($this->handlers->types() as $type) {
            $def = $map[$type] ?? null;
            $out[] = ['type' => $type, 'queue' => $this->handlers->defaultQueue($type), 'handler' => is_array($def) ? (string) ($def['handler'] ?? '') : (string) $def];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function commands(): array
    {
        $map = require dirname(__DIR__, 3) . '/config/console/commands.php';
        ksort($map);
        return $map;
    }

    /** @return array<string, array<string,string>> section => [label => value]; explicit allowlist, no secrets */
    private function environment(): array
    {
        $pg = $redis = 'unavailable';
        try {
            $pg = (string) $this->db->createCommand('SHOW server_version')->queryScalar();
        } catch (Throwable) {
        }
        try {
            $info = $this->redis->info('server');
            $redis = (string) ($info['Server']['redis_version'] ?? $info['redis_version'] ?? 'unknown');
        } catch (Throwable) {
        }
        return [
            'Platform' => [
                'Product' => 'Yii3 Enterprise Platform · built by ShokirjonMK',
                'APP_ENV' => Environment::appEnv(),
                'APP_DEBUG' => Environment::appDebug() ? 'true' : 'false',
                'Server time' => date('Y-m-d H:i:s T'),
                'Default time zone' => date_default_timezone_get(),
            ],
            'PHP' => [
                'Version' => PHP_VERSION,
                'SAPI' => PHP_SAPI,
                'OS' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'opcache.enable' => (string) ini_get('opcache.enable'),
                'opcache.validate_timestamps' => (string) ini_get('opcache.validate_timestamps'),
                'apc.enabled' => (string) ini_get('apc.enabled'),
                'display_errors' => (string) ini_get('display_errors'),
            ],
            'Services' => [
                'PostgreSQL' => $pg,
                'Redis' => $redis,
                'librdkafka' => defined('RD_KAFKA_VERSION') ? sprintf('%d.%d.%d', (RD_KAFKA_VERSION >> 24) & 0xff, (RD_KAFKA_VERSION >> 16) & 0xff, (RD_KAFKA_VERSION >> 8) & 0xff) : 'not loaded',
                'libsodium' => defined('SODIUM_LIBRARY_VERSION') ? SODIUM_LIBRARY_VERSION : 'not loaded',
                'OpenSSL' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'not loaded',
                'cURL' => function_exists('curl_version') ? (string) (curl_version()['version'] ?? '') : 'not loaded',
            ],
        ];
    }

    /** @return array<string,string> */
    private function extensions(): array
    {
        $out = [];
        foreach (get_loaded_extensions() as $ext) {
            $out[$ext] = (string) (phpversion($ext) ?: '');
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
}
