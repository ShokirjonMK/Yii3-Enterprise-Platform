<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use Psr\Container\ContainerInterface;
use Yiisoft\Config\Config;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Config\ConfigPaths;
use Yiisoft\Config\Modifier\RecursiveMerge;
use Yiisoft\Config\Modifier\ReverseMerge;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * Builds the real application DI container (same config as the runners) for integration tests
 * and for fixtures in HTTP test suites.
 */
final class TestContainer
{
    /** @var array<string, ContainerInterface> */
    private static array $containers = [];

    public static function get(string $group = 'console'): ContainerInterface
    {
        if (isset(self::$containers[$group])) {
            return self::$containers[$group];
        }
        $root = dirname(__DIR__, 2);
        $config = new Config(
            new ConfigPaths($root, 'config'),
            Environment::appEnv(),
            [
                ReverseMerge::groups('events', 'events-web', 'events-console'),
                RecursiveMerge::groups('params', 'params-web', 'params-console', 'events', 'events-web', 'events-console'),
            ],
            paramsGroup: $group === 'web' ? 'params-web' : 'params-console',
        );
        $containerConfig = ContainerConfig::create()
            ->withDefinitions(array_merge($config->get('di-' . $group), [ConfigInterface::class => $config]))
            ->withProviders($config->has('di-providers-' . $group) ? $config->get('di-providers-' . $group) : [])
            ->withDelegates($config->has('di-delegates-' . $group) ? $config->get('di-delegates-' . $group) : [])
            ->withTags($config->has('di-tags-' . $group) ? $config->get('di-tags-' . $group) : []);
        return self::$containers[$group] = new Container($containerConfig);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function service(string $id, string $group = 'console'): object
    {
        /** @var T */
        return self::get($group)->get($id);
    }

    public static function reset(): void
    {
        self::$containers = [];
    }
}
