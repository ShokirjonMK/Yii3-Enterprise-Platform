<?php

declare(strict_types=1);

/**
 * Kafka consumer groups. Modules add files to config/common/events/*.php:
 *   'group-name' => ['topics' => ['projects.events'], 'handlers' => ['project.created' => Handler::class]]
 * Group "platform-retry" is built in (retry topics → original topics).
 */
$groups = [
    'platform-default' => ['topics' => ['platform.users', 'platform.notifications'], 'handlers' => []],
    'platform-retry' => ['topics' => [], 'handlers' => []],
];

foreach ([...(glob(__DIR__ . '/events/*.php') ?: []), ...(glob(dirname(__DIR__, 2) . '/src/Modules/*/config/events.php') ?: [])] as $file) {
    foreach (require $file as $group => $def) {
        $groups[$group] ??= ['topics' => [], 'handlers' => []];
        $groups[$group]['topics'] = array_values(array_unique([...$groups[$group]['topics'], ...($def['topics'] ?? [])]));
        $groups[$group]['handlers'] = array_merge($groups[$group]['handlers'], $def['handlers'] ?? []);
    }
}

return $groups;
