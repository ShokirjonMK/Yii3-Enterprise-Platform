<?php

declare(strict_types=1);

return [
    '@root' => dirname(__DIR__, 2),
    '@src' => '@root/src',
    // code areas (controllers + their views/ + routes.php live together)
    '@admin' => '@root/src/Admin',
    '@web' => '@root/src/Web',
    '@api' => '@root/src/Api',
    '@modules' => '@root/src/Modules',
    '@assets' => '@root/public/assets',
    '@assetsUrl' => '@baseUrl/assets',
    '@assetsSource' => '@root/assets',
    '@baseUrl' => '/',
    '@public' => '@root/public',
    '@runtime' => '@root/runtime',
    '@vendor' => '@root/vendor',
    '@views' => '@root/resources/views',
    '@messages' => '@root/resources/messages',
    '@migrations' => '@root/migrations',
];
