<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Admin → Databases against the running stack: list + live status of every named connection, objects, read-only
 * console (write refused), superadmin CRUD of a panel connection (SQLite) with write mode, and the boundaries for
 * the general admin role (view only).
 */
final class DatabasesAdminTest extends TestCase
{
    private string $conn = '';

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        $this->conn = 'e2e_' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        try {
            TestContainer::service(ConnectionInterface::class, 'web')->createCommand()->delete('database_connections', ['like', 'name', 'e2e_%', null])->execute();
        } catch (\Throwable) {
        }
        Fixtures::cleanup();
    }

    public function testSuperadminManagesConnectionsAndConsole(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        self::assertSame(200, $web->get('/admin/databases')->getStatusCode());
        self::assertStringContainsString('Supported engines', $web->body());
        self::assertStringContainsString('/admin/databases/platform/status', $web->body());

        $web->get('/admin/databases/platform/status');
        self::assertStringContainsString('data-db-status="ok"', $web->body());

        $web->get('/admin/databases/platform');
        self::assertStringContainsString('public.users', $web->body(), 'tables of the platform database are listed');

        // read mode: SELECT works, a write statement is refused before reaching the database
        AdminSession::reauth($web, $user);
        $web->post('/admin/databases/platform/console', ['statement' => 'SELECT 41 + 1 AS answer', 'mode' => 'read', 'limit' => '10']);
        self::assertStringContainsString('data-query-status="ok"', $web->body());
        self::assertStringContainsString('>42<', $web->body());
        $r = $web->post('/admin/databases/platform/console', ['statement' => 'DELETE FROM users', 'mode' => 'read']);
        self::assertSame(422, $r->getStatusCode());
        $r = $web->post('/admin/databases/platform/console', ['statement' => "WITH x AS (DELETE FROM users WHERE false RETURNING 1) SELECT * FROM x", 'mode' => 'read']);
        self::assertStringContainsString('read-only transaction', $web->body(), 'PostgreSQL itself refuses writes in read mode');

        // a panel connection (SQLite file): create, test, write, read, disable, delete
        $web->get('/admin/databases/new');
        $web->post('/admin/databases', ['name' => $this->conn, 'driver' => 'sqlite', 'database' => $this->conn . '.sqlite', 'enabled' => '1', 'description' => 'e2e']);
        self::assertSame('/admin/databases/' . $this->conn, $web->url());
        $web->post('/admin/databases/' . $this->conn . '/test');
        self::assertStringContainsString('data-test-status="ok"', $web->body());

        $web->get('/admin/databases/' . $this->conn . '/console');
        self::assertStringContainsString('Write (changes data, committed)', $web->body(), 'superadmin may use write mode');
        $web->post('/admin/databases/' . $this->conn . '/console', ['statement' => 'CREATE TABLE notes (id integer primary key, body text)', 'mode' => 'write']);
        self::assertStringContainsString('data-query-status="ok"', $web->body());
        $web->post('/admin/databases/' . $this->conn . '/console', ['statement' => "INSERT INTO notes (body) VALUES ('hello')", 'mode' => 'write']);
        $web->post('/admin/databases/' . $this->conn . '/console', ['statement' => 'SELECT body FROM notes', 'mode' => 'read']);
        self::assertStringContainsString('>hello<', $web->body());

        $web->post('/admin/databases/' . $this->conn . '/toggle', ['enabled' => '0']);
        $web->get('/admin/databases/' . $this->conn);
        self::assertStringContainsString('The connection is disabled.', $web->body());
        $web->post('/admin/databases/' . $this->conn . '/delete');
        self::assertSame('/admin/databases', $web->url());
        self::assertStringNotContainsString('/admin/databases/' . $this->conn . '"', $web->body());

        // .env / platform connections cannot be edited in the panel
        $web->get('/admin/databases/platform/edit');
        self::assertSame('/admin/databases/platform', $web->url());
    }

    public function testGeneralAdminCanViewButNotManageOrQuery(): void
    {
        [$web] = AdminSession::login(['admin']);
        self::assertSame(200, $web->get('/admin/databases')->getStatusCode());
        self::assertSame(403, $web->get('/admin/databases/new')->getStatusCode());
        self::assertSame(403, $web->get('/admin/databases/platform/console')->getStatusCode());

        [$plain] = AdminSession::login(['user']);
        self::assertSame(403, $plain->get('/admin/databases')->getStatusCode());
    }
}
