<?php

declare(strict_types=1);

use Yiisoft\Aliases\Aliases;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;

/** @var array $params */

return [
    'translation.app' => [
        'definition' => static function (Aliases $aliases) use ($params): CategorySource {
            $source = new MessageSource($aliases->get('@messages'));
            return new CategorySource($params['yiisoft/translator']['defaultCategory'], $source, new IntlMessageFormatter());
        },
        'tags' => ['translation.categorySource'],
    ],
];
