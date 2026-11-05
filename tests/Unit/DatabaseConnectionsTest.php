<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Database\Connections\ConnectionUrl;
use App\Platform\Database\Connections\DatabaseDriver;
use App\Platform\Database\Connections\QueryConsole;
use App\Shared\Domain\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionsTest extends TestCase
{
    public function testParsesUrlsOfEveryEngine(): void
    {
        $pg = ConnectionUrl::parse('analytics', 'postgres://app:p%40ss%3Aw0rd@db2:6432/analytics?sslmode=require&readonly=1');
        self::assertSame([DatabaseDriver::PGSQL, 'db2', 6432, 'analytics', 'app', 'p@ss:w0rd', true], [$pg->driver, $pg->host, $pg->port, $pg->database, $pg->username, $pg->password, $pg->readOnly]);
        self::assertSame(['sslmode' => 'require'], $pg->options);
        self::assertSame('pgsql://app@db2:6432/analytics', $pg->target(), 'no password in the display target');

        self::assertSame(3306, ConnectionUrl::parse('shop', 'mariadb://u:p@m/shop')->port());
        self::assertSame(DatabaseDriver::SQLSRV, ConnectionUrl::parse('erp', 'mssql://sa:x@sql/erp')->driver);
        self::assertSame(DatabaseDriver::OCI, ConnectionUrl::parse('ora', 'oracle://scott:tiger@ora:1521/XEPDB1')->driver);

        $sqlite = ConnectionUrl::parse('local', 'sqlite:///app/runtime/local.sqlite');
        self::assertSame('/app/runtime/local.sqlite', $sqlite->option('path'));

        $srv = ConnectionUrl::parse('docs', 'mongodb+srv://u:p@cluster0.example.net/docs?authSource=admin&tls=true');
        self::assertSame([DatabaseDriver::MONGODB, true, true, 'admin'], [$srv->driver, $srv->option('srv'), $srv->option('tls'), $srv->option('authSource')]);

        $ch = ConnectionUrl::parse('events', 'clickhouses://default:x@ch.example.com:8443/events');
        self::assertSame([DatabaseDriver::CLICKHOUSE, true, 8443], [$ch->driver, $ch->option('tls'), $ch->port()]);
    }

    public function testRejectsUnknownSchemes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConnectionUrl::parse('x', 'redis://localhost');
    }

    /** @return iterable<array{string, bool}> */
    public static function statements(): iterable
    {
        yield ['SELECT * FROM users', true];
        yield ['  with x as (select 1) select * from x;', true];
        yield ['EXPLAIN ANALYZE SELECT 1', true];
        yield ["SELECT 'a;b' AS semicolon_in_string", true];
        yield ['-- comment' . "\n" . 'SHOW TABLES', true];
        yield ['DELETE FROM users', false];
        yield ['SELECT 1; DROP TABLE users', false];
        yield ['/* select */ UPDATE users SET x = 1', false];
        yield ['PRAGMA query_only = OFF', false];
        yield ['insert into t values (1)', false];
    }

    #[DataProvider('statements')]
    public function testReadModeStatementCheck(string $sql, bool $allowed): void
    {
        try {
            QueryConsole::assertReadOnlySql($sql);
            self::assertTrue($allowed, "must be refused: $sql");
        } catch (ValidationException) {
            self::assertFalse($allowed, "must be allowed: $sql");
        }
    }
}
