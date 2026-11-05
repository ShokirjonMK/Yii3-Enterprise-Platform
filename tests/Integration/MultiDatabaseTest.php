<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Database\Connections\ConnectionStore;
use App\Platform\Database\Connections\DatabaseManager;
use App\Platform\Database\Connections\QueryConsole;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/**
 * Several databases of different engines used at the same time through DatabaseManager — against the dev containers
 * of the "databases" compose profile (connections from .env). A connection that is not configured/reachable is skipped,
 * so the suite still runs where those servers do not exist.
 */
final class MultiDatabaseTest extends IntegrationTestCase
{
    private DatabaseManager $dbs;
    private string $admin = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbs = $this->get(DatabaseManager::class);
        $this->admin = Fixtures::user(['superadmin'])['id'];
    }

    protected function tearDown(): void
    {
        $this->db->createCommand()->delete('database_connections', ['like', 'name', 'it_%', null])->execute();
        parent::tearDown();
    }

    private function need(string $name): void
    {
        if (!$this->dbs->has($name)) {
            self::markTestSkipped("Connection \"$name\" is not configured (DB_CONNECTIONS).");
        }
        $r = $this->dbs->test($this->dbs->definition($name));
        if (!$r['ok']) {
            self::markTestSkipped("Connection \"$name\" is unreachable: {$r['error']}");
        }
    }

    public function testThreePostgresDatabasesAtOnce(): void
    {
        $this->need('analytics');
        $this->need('archive');
        $t = 'it_' . bin2hex(random_bytes(4));
        foreach (['analytics', 'archive'] as $i => $name) {
            $c = $this->dbs->sql($name);
            $c->createCommand("CREATE TABLE $t (id int PRIMARY KEY, src text)")->execute();
            $c->createCommand()->insert($t, ['id' => $i + 1, 'src' => $name])->execute();
        }
        try {
            self::assertSame('analytics', (new Query($this->dbs->sql('analytics')))->select('src')->from($t)->scalar());
            self::assertSame('archive', (new Query($this->dbs->sql('archive')))->select('src')->from($t)->scalar());
            self::assertSame($this->db, $this->dbs->sql('platform'), 'platform = the primary connection');
            self::assertNotSame($this->dbs->sql('analytics'), $this->dbs->sql('archive'));
            self::assertSame($this->dbs->sql('analytics'), $this->dbs->sql('analytics'), 'reused within the process');
        } finally {
            $this->dbs->sql('analytics')->createCommand("DROP TABLE $t")->execute();
            $this->dbs->sql('archive')->createCommand("DROP TABLE $t")->execute();
        }
    }

    public function testMongoDb(): void
    {
        $this->need('docs');
        $coll = $this->dbs->mongo('docs')->selectCollection('it_' . bin2hex(random_bytes(4)));
        try {
            $coll->insertMany([['k' => 'a', 'n' => 1], ['k' => 'b', 'n' => 2]]);
            self::assertSame(2, $coll->countDocuments());
            self::assertSame(2, $coll->findOne(['k' => 'b'])['n']);

            $out = $this->get(QueryConsole::class)->run($this->admin, 'docs', json_encode(['collection' => $coll->getCollectionName(), 'find' => ['n' => ['$gt' => 1]]]), false);
            self::assertNull($out['error']);
            self::assertSame('b', $out['rows'][0]['k']);
            $this->expectException(ValidationException::class);
            $this->get(QueryConsole::class)->run($this->admin, 'docs', json_encode(['collection' => $coll->getCollectionName(), 'deleteMany' => []]), false);
        } finally {
            $coll->drop();
        }
    }

    public function testClickHouse(): void
    {
        $this->need('events');
        $ch = $this->dbs->clickhouse('events');
        $t = 'it_' . bin2hex(random_bytes(4));
        $ch->execute("CREATE TABLE $t (day Date, event String, n UInt32) ENGINE = MergeTree ORDER BY day");
        try {
            self::assertSame(3, $ch->insert($t, [
                ['day' => '2026-11-05', 'event' => 'login', 'n' => 2],
                ['day' => '2026-11-05', 'event' => 'login', 'n' => 3],
                ['day' => '2026-11-04', 'event' => 'view', 'n' => 1],
            ]));
            $rows = $ch->select("SELECT event, sum(n) AS total FROM $t WHERE day = {d:Date} GROUP BY event", ['d' => '2026-11-05']);
            self::assertSame([['event' => 'login', 'total' => '5']], $rows);

            // read mode in the console: ClickHouse readonly=2 refuses writes even if the statement check were bypassed
            $out = $this->get(QueryConsole::class)->run($this->admin, 'events', "SELECT count() AS c FROM $t", false);
            self::assertSame('3', $out['rows'][0]['c']);
        } finally {
            $ch->execute("DROP TABLE $t");
        }
    }

    public function testMysqlMariaDb(): void
    {
        $this->need('shop');
        $c = $this->dbs->sql('shop');
        $t = 'it_' . bin2hex(random_bytes(4));
        $c->createCommand("CREATE TABLE $t (id int PRIMARY KEY, name varchar(20))")->execute();
        try {
            $c->createCommand()->insert($t, ['id' => 1, 'name' => 'mysql'])->execute();
            self::assertSame('mysql', (new Query($c))->select('name')->from($t)->where(['id' => 1])->scalar());
            $out = $this->get(QueryConsole::class)->run($this->admin, 'shop', "SELECT name FROM $t", false);
            self::assertSame('mysql', $out['rows'][0]['name']);
        } finally {
            $c->createCommand("DROP TABLE $t")->execute();
        }
    }

    public function testPanelConnectionLifecycleAndReadOnly(): void
    {
        $store = $this->get(ConnectionStore::class);
        $name = 'it_' . bin2hex(random_bytes(3));
        $store->create($this->admin, ['name' => $name, 'driver' => 'sqlite', 'database' => $name . '.sqlite', 'password' => 'not-used-secret']);
        $raw = (string) (new Query($this->db))->select('password_enc')->from('database_connections')->where(['name' => $name])->scalar();
        self::assertStringNotContainsString('not-used-secret', $raw, 'password encrypted at rest');
        self::assertSame('not-used-secret', $store->find($name)->password);

        $this->dbs->sql($name)->createCommand('CREATE TABLE t (x int)')->execute();
        $store->update($this->admin, $name, ['driver' => 'sqlite', 'database' => $name . '.sqlite', 'read_only' => '1']);
        $this->dbs->forget($name);
        try {
            $this->dbs->sql($name)->createCommand('INSERT INTO t VALUES (1)')->execute();
            self::fail('read-only SQLite connection must refuse writes');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('readonly', str_replace(['-', ' '], '', $e->getMessage()));
        }

        $store->setEnabled($this->admin, $name, false);
        try {
            $this->dbs->sql($name);
            self::fail('disabled connection must not open');
        } catch (ConflictException) {
        }
        self::assertSame(1, (int) (new Query($this->db))->from('audit_log')->where(['entity_id' => $name, 'action' => 'database.connection.created'])->count());

        try {
            $store->update($this->admin, 'platform', ['driver' => 'pgsql']);
            self::fail('platform connection is not editable');
        } catch (ConflictException) {
        }
        $store->delete($this->admin, $name);
        self::assertFalse($store->exists($name));
        @unlink(dirname(__DIR__, 2) . "/runtime/databases/$name.sqlite");
    }
}
