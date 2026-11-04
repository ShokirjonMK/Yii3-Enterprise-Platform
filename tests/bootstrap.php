<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

App\Environment::prepare();

/*
 * Integration suites run with APP_ENV=test against the dedicated database (DB_TEST_NAME, default
 * platform_test). It is migrated, RBAC-synced and seeded once per run via the real console commands.
 */
if (App\Environment::isTest() && getenv('SKIP_TEST_DB_SETUP') !== '1') {
    $root = dirname(__DIR__);
    foreach (['migrate:up --no-interaction', 'rbac:sync', 'platform:seed'] as $cmd) {
        exec('APP_ENV=test php ' . escapeshellarg($root . '/yii') . ' ' . $cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            fwrite(STDERR, "Test DB setup failed at \"$cmd\":\n" . implode("\n", array_slice($out, -20)) . "\n");
            exit(1);
        }
    }
}
