<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Database\PostgresInspector;
use App\Platform\Redis\RedisInspector;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Predis\ClientInterface;
use Yiisoft\Db\Query\Query;

/**
 * PostgresInspector and RedisInspector against the real test database / Redis DB.
 */
final class RuntimeInspectorsTest extends IntegrationTestCase
{
    public function testPostgresOverviewAndStatistics(): void
    {
        $pg = $this->get(PostgresInspector::class);
        $o = $pg->overview();
        self::assertStringStartsWith('PostgreSQL', (string) $o['version']);
        self::assertGreaterThan(0, (int) $o['size_bytes']);
        self::assertGreaterThan(0, (int) $o['uptime_seconds']);
        self::assertNotEmpty($pg->connectionsByState());
        self::assertNotEmpty($pg->tables(10));
        self::assertNotEmpty($pg->indexes(10));
        self::assertIsArray($pg->unusedIndexes());
        self::assertIsArray($pg->bloat());
        self::assertIsArray($pg->blocking());
        self::assertNotEmpty($pg->lockSummary());
        $repl = $pg->replication();
        self::assertFalse($repl['in_recovery']);
        self::assertIsArray($repl['standbys']);
        self::assertArrayHasKey('archived_count', (array) $repl['archiver']);
        $parts = array_column($pg->partitions('audit_log'), 'name');
        self::assertNotEmpty($parts, 'audit_log is partitioned');
        self::assertContains('audit_log_p' . date('Ym'), $parts);
        self::assertContains('public.jobs', $pg->maintainableTables());
    }

    public function testPgStatStatementsHandledGracefully(): void
    {
        $pg = $this->get(PostgresInspector::class);
        $status = $pg->statementsStatus();
        if ($status['installed']) {
            self::assertIsArray($pg->slowQueries('total', 5));
        } else {
            self::assertNotNull($status['reason']);
            self::assertSame([], $pg->slowQueries());
        }
    }

    public function testQueryTextIsRedactedAndTruncated(): void
    {
        $q = PostgresInspector::redactQuery("SELECT * FROM users WHERE email = 'alice@example.com' AND password_hash = 'x' -- token=abc123\n" . str_repeat('x', 1000));
        self::assertStringNotContainsString('alice@example.com', $q);
        self::assertStringNotContainsString('abc123', $q);
        self::assertStringEndsWith('…', $q);
        self::assertLessThanOrEqual(PostgresInspector::QUERY_PREVIEW + 1, mb_strlen($q));
    }

    public function testActivityListsOwnSessionAndHidesLiterals(): void
    {
        $pg = $this->get(PostgresInspector::class);
        $rows = $pg->activity();
        $self = array_values(array_filter($rows, static fn($r) => $r['is_self']));
        self::assertCount(1, $self);
        self::assertFalse($self[0]['cancellable'], 'own session is never cancellable');
    }

    public function testCancelBackendRefusesForeignAndUnknownPids(): void
    {
        $pg = $this->get(PostgresInspector::class);
        // background processes (checkpointer, autovacuum launcher…) do not belong to the application role
        $foreign = (int) $this->db->createCommand("SELECT pid FROM pg_stat_activity WHERE backend_type <> 'client backend' ORDER BY pid LIMIT 1")->queryScalar();
        self::assertGreaterThan(0, $foreign);
        try {
            $pg->cancelBackend($foreign, null);
            self::fail('Foreign pid must be refused');
        } catch (AccessDeniedException) {
        }
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'db.cancel_backend', 'entity_id' => (string) $foreign, 'result' => 'denied'])->exists());

        // own backend is refused too
        $own = (int) $this->db->createCommand('SELECT pg_backend_pid()')->queryScalar();
        $this->expectException(AccessDeniedException::class);
        $pg->cancelBackend($own, null);
    }

    public function testCancelBackendUnknownPid(): void
    {
        $this->expectException(NotFoundException::class);
        $this->get(PostgresInspector::class)->cancelBackend(999999, null);
    }

    public function testMaintenanceOnlyForWhitelistedTables(): void
    {
        $pg = $this->get(PostgresInspector::class);
        $pg->maintain('public.jobs', 'analyze', null);
        $pg->maintain('public.jobs', 'vacuum', null);
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'db.vacuum', 'entity_id' => 'public.jobs'])->exists());
        foreach ([['public.jobs; DROP TABLE users', 'analyze'], ['pg_catalog.pg_class', 'analyze'], ['public.jobs', 'drop']] as [$table, $op]) {
            try {
                $pg->maintain($table, $op, null);
                self::fail("$op $table must be rejected");
            } catch (ValidationException) {
            }
        }
    }

    public function testPartitionMaintenanceIsEnqueued(): void
    {
        $id = $this->get(PostgresInspector::class)->enqueuePartitionMaintenance(null);
        self::assertSame('platform.maintenance.partitions', (new Query($this->db))->select('type')->from('jobs')->where(['id' => $id])->scalar());
    }

    public function testSearchDiagnostics(): void
    {
        $pg = $this->get(PostgresInspector::class);
        $ext = array_column($pg->extensions(), 'installed', 'name');
        self::assertNotNull($ext['pg_trgm'], 'pg_trgm installed in the test database');
        self::assertIsArray($pg->searchIndexes());
        $d = $pg->searchDiagnostics('admin');
        self::assertTrue($d['trigram_available']);
        self::assertStringContainsString('adm', (string) $d['trigrams']);
        self::assertNotEmpty($d['users_plan']);
    }

    // ------------------------------------------------------------------ Redis

    private function redis(): ClientInterface
    {
        return $this->get(ClientInterface::class); // prefixed client (application prefix), "state" role
    }

    /** Prefixed client of the "cache" role (a separate instance when REDIS_CACHE_HOST is set). */
    private function cacheRedis(): ClientInterface
    {
        $params = $this->get(\Yiisoft\Config\ConfigInterface::class)->get('params-console');
        return \App\Shared\Infrastructure\Redis\RedisClientFactory::create(['role' => 'cache-test'] + $params['platform']['redisCache']);
    }

    public function testRedisInfoSlowlogLatencyAndTopology(): void
    {
        $r = $this->get(RedisInspector::class);
        self::assertTrue($r->ping()['ok']);
        $info = $r->info();
        self::assertNotNull($info['derived']['version']);
        self::assertSame('noeviction', $info['derived']['maxmemory_policy'], 'the state instance (sessions, locks) never evicts');
        self::assertArrayHasKey('Memory', $info['sections']);
        self::assertIsArray($r->slowlog(5));
        self::assertIsArray($r->latency());
        $topo = $r->topology();
        self::assertSame('standalone', $topo['mode']);
        self::assertArrayNotHasKey('password', $topo);
    }

    public function testKeyBrowserUsesPrefixAndHidesSensitiveValues(): void
    {
        $r = $this->get(RedisInspector::class);
        $redis = $this->redis();
        $tag = bin2hex(random_bytes(4));
        $redis->set("sess:rt-$tag", 'user-session-secret-payload');
        $redis->set("cache:rt-$tag", 'cached-value');
        $redis->expire("cache:rt-$tag", 600);

        $found = array_column($r->scan('', '0', 500)['keys'], null, 'key');
        self::assertArrayHasKey("sess:rt-$tag", $found);
        self::assertTrue($found["sess:rt-$tag"]['sensitive']);
        self::assertFalse($found["cache:rt-$tag"]['sensitive']);
        self::assertGreaterThan(0, $found["cache:rt-$tag"]['ttl']);

        $sess = $r->inspect("sess:rt-$tag");
        self::assertNull($sess['preview'], 'session values are never shown');
        self::assertGreaterThan(0, (int) $sess['size']);
        self::assertSame('cached-value', $r->inspect("cache:rt-$tag")['preview']);

        $this->expectException(ValidationException::class);
        $r->scan('sess:*');
    }

    public function testCacheClearCannotTouchSessionKeys(): void
    {
        $r = $this->get(RedisInspector::class);
        $redis = $this->redis();
        $cache = $this->cacheRedis();
        $tag = bin2hex(random_bytes(4));
        $redis->set("sess:cc-$tag", 's');
        $redis->set("lock:cc-$tag", 'l');
        $cache->set("cache:cc-$tag:a", '1');
        $cache->set("cache:cc-$tag:b", '2');
        $cache->set("cache:other-$tag", '3');

        self::assertSame(2, $r->clearCache("cc-$tag", null), 'cleared on the cache instance');
        self::assertSame(0, (int) $cache->exists("cache:cc-$tag:a"));
        self::assertSame(1, (int) $cache->exists("cache:other-$tag"), 'sub-namespace clear is scoped');
        self::assertSame(1, (int) $redis->exists("sess:cc-$tag"));
        self::assertSame(1, (int) $redis->exists("lock:cc-$tag"));

        foreach (['*', '../sess:', 'x?', '[a-z]'] as $evil) {
            try {
                $r->clearCache($evil, null);
                self::fail("pattern $evil must be rejected");
            } catch (ValidationException) {
            }
        }
        // full cache namespace clear still leaves sessions and locks intact
        $r->clearCache('', null);
        self::assertSame(1, (int) $redis->exists("sess:cc-$tag"));
        self::assertSame(1, (int) $redis->exists("lock:cc-$tag"));
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'redis.cache.cleared'])->exists());
        $redis->del(["sess:cc-$tag", "lock:cc-$tag"]);
    }

    public function testInfoParser(): void
    {
        $s = RedisInspector::parseInfo("# Server\r\nredis_version:7.4.1\r\n\r\n# Keyspace\r\ndb0:keys=3,expires=1,avg_ttl=0\r\n");
        self::assertSame('7.4.1', $s['Server']['redis_version']);
        self::assertSame('keys=3,expires=1,avg_ttl=0', $s['Keyspace']['db0']);
    }
}
