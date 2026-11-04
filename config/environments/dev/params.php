<?php

declare(strict_types=1);

return [
    'traceLink' => 'phpstorm://open?url=file://{file}&line={line}',

    // Re-publish CSS/JS on every request in development; production images publish once (immutable).
    'yiisoft/assets' => [
        'assetPublisher' => [
            'forceCopy' => true,
            'linkAssets' => false,
        ],
        'assetLoader' => [
            'appendTimestamp' => true,
        ],
    ],
];
