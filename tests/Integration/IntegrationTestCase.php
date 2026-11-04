<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

abstract class IntegrationTestCase extends TestCase
{
    protected ContainerInterface $c;
    protected ConnectionInterface $db;

    protected function setUp(): void
    {
        if (!Environment::isTest()) {
            self::markTestSkipped('Integration tests require APP_ENV=test (dedicated test database).');
        }
        $this->c = TestContainer::get();
        $this->db = $this->c->get(ConnectionInterface::class);
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function get(string $id): object
    {
        return $this->c->get($id);
    }
}
