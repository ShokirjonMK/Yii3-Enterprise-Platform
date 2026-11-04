<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Creates a Predis client for standalone, Sentinel (HA) or Cluster topologies.
 */
final class RedisClientFactory
{
    /**
     * @param array{mode:string,host:string,port:int,password:?string,database:int,sentinels:list<string>,sentinelService:string,prefix:string,timeout:float} $config
     */
    public static function create(array $config, bool $withPrefix = true): ClientInterface
    {
        $parameters = [
            'password' => $config['password'],
            'database' => $config['database'],
            'timeout' => $config['timeout'],
            'read_write_timeout' => 3.0,
        ];
        $options = $withPrefix ? ['prefix' => $config['prefix']] : [];

        return match ($config['mode']) {
            'sentinel' => new Client(
                array_map(static fn(string $s) => 'tcp://' . $s, $config['sentinels']),
                $options + [
                    'replication' => 'sentinel',
                    'service' => $config['sentinelService'],
                    'parameters' => $parameters,
                ],
            ),
            'cluster' => new Client(
                array_map(static fn(string $s) => 'tcp://' . $s, $config['sentinels'] ?: [$config['host'] . ':' . $config['port']]),
                $options + ['cluster' => 'redis', 'parameters' => ['password' => $config['password'], 'timeout' => $config['timeout']]],
            ),
            default => new Client(
                ['scheme' => 'tcp', 'host' => $config['host'], 'port' => $config['port']] + $parameters,
                $options,
            ),
        };
    }
}
