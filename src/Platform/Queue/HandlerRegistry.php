<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use Psr\Container\ContainerInterface;

/**
 * Maps job types to handler services. Definitions come from config/common/jobs.php (+ module files
 * config/common/jobs/*.php):
 *   'type' => HandlerClass::class                     (queue "default")
 *   'type' => ['handler' => HandlerClass::class, 'queue' => 'notifications']
 */
final class HandlerRegistry
{
    /** @param array<string, string|array{handler:string, queue?:string}> $map */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $map,
    ) {}

    public function has(string $type): bool
    {
        return isset($this->map[$type]);
    }

    public function get(string $type): JobHandlerInterface
    {
        $def = $this->map[$type] ?? throw new PermanentJobFailure("No handler for job type \"$type\".");
        $class = is_array($def) ? $def['handler'] : $def;
        $handler = $this->container->get($class);
        if (!$handler instanceof JobHandlerInterface) {
            throw new PermanentJobFailure("$class must implement JobHandlerInterface.");
        }
        return $handler;
    }

    public function defaultQueue(string $type): string
    {
        $def = $this->map[$type] ?? null;
        return is_array($def) ? ($def['queue'] ?? 'default') : 'default';
    }

    /** @return list<string> */
    public function types(): array
    {
        $t = array_keys($this->map);
        sort($t);
        return $t;
    }
}
