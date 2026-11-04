<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use App\Platform\Messaging\EventHandlerInterface;
use App\Platform\Messaging\Kafka\Attribute\KafkaChannel;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Finds every class under the given source directories that carries {@see KafkaChannel} and merges them
 * into the consumer group map of config/common/events.php:
 *
 *   group => ['topics' => [...], 'handlers' => [eventType => class], 'raw' => [topic => [class, ...]]]
 *
 * Only files that mention "KafkaChannel" are loaded (cheap). Classes are autoloaded through Composer
 * (PSR-4), never `require`d directly. Result is cached per process.
 *
 * Author: ShokirjonMK
 */
final class KafkaHandlerDiscovery
{
    /** @var list<array{class:string, topic:string, group:string, events:list<string>, mode:string, description:string}>|null */
    private ?array $found = null;

    /** @param list<string> $paths */
    public function __construct(private readonly array $paths) {}

    /** @return list<array{class:string, topic:string, group:string, events:list<string>, mode:string, description:string}> */
    public function handlers(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }
        $found = [];
        foreach ($this->paths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (!str_contains($src, 'KafkaChannel(') || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'Attribute' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $class = self::classFromSource($src);
                if ($class === null || !class_exists($class)) {
                    continue;
                }
                $ref = new ReflectionClass($class);
                if ($ref->isAbstract()) {
                    continue;
                }
                $mode = match (true) {
                    $ref->implementsInterface(KafkaMessageHandlerInterface::class) => 'raw',
                    $ref->implementsInterface(EventHandlerInterface::class) => 'event',
                    default => null,
                };
                if ($mode === null) {
                    continue;
                }
                foreach ($ref->getAttributes(KafkaChannel::class) as $attr) {
                    /** @var KafkaChannel $meta */
                    $meta = $attr->newInstance();
                    $found[] = [
                        'class' => $class,
                        'topic' => $meta->topic,
                        'group' => $meta->group,
                        'events' => array_values($meta->events),
                        'mode' => $mode,
                        'description' => $meta->description,
                    ];
                }
            }
        }
        usort($found, static fn($a, $b) => [$a['group'], $a['topic'], $a['class']] <=> [$b['group'], $b['topic'], $b['class']]);
        return $this->found = $found;
    }

    /**
     * @param array<string, array{topics:list<string>, handlers:array<string,string>, raw?:array<string,list<string>>}> $groups
     * @return array<string, array{topics:list<string>, handlers:array<string,string>, raw:array<string,list<string>>}>
     */
    public function merge(array $groups): array
    {
        foreach ($groups as $g => $def) {
            $groups[$g]['raw'] ??= [];
        }
        foreach ($this->handlers() as $h) {
            $g = &$groups[$h['group']];
            $g ??= ['topics' => [], 'handlers' => [], 'raw' => []];
            if (!in_array($h['topic'], $g['topics'], true)) {
                $g['topics'][] = $h['topic'];
            }
            if ($h['mode'] === 'raw') {
                $g['raw'][$h['topic']][] = $h['class'];
            } else {
                foreach ($h['events'] === [] ? ['*'] : $h['events'] as $type) {
                    $g['handlers'][$type] = $h['class'];
                }
            }
            unset($g);
        }
        return $groups;
    }

    public static function classFromSource(string $src): ?string
    {
        if (!preg_match('/^namespace\s+([^;\s]+)\s*;/m', $src, $ns) || !preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $src, $cl)) {
            return null;
        }
        return $ns[1] . '\\' . $cl[1];
    }
}
