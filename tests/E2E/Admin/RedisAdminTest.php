<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Yiisoft\Db\Query\Query;

/**
 * 13 · Redis Management over HTTP against the dev Redis (application prefix).
 */
final class RedisAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function redis(): ClientInterface
    {
        return TestContainer::service(ClientInterface::class);
    }

    public function testOverviewAndKeyBrowser(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $web->get('/admin/redis');
        self::assertStringContainsString('allkeys-lru', $web->body());
        self::assertStringContainsString('Outage policy', $web->body());
        self::assertStringContainsString('fail-closed', $web->body());
        self::assertStringNotContainsString('Redis unreachable', $web->body());

        $tag = bin2hex(random_bytes(4));
        $this->redis()->set("cache:e2e-$tag", 'visible-cache-value');
        $this->redis()->set("e2e-$tag:api_token", 'tok-should-never-show');
        $web->get('/admin/redis/keys?prefix=cache:e2e-' . $tag);
        self::assertStringContainsString("cache:e2e-$tag", $web->body());
        $web->htmx('GET', '/admin/redis/keys/inspect?key=' . rawurlencode("cache:e2e-$tag"));
        self::assertStringContainsString('visible-cache-value', $web->body());
        $web->htmx('GET', '/admin/redis/keys/inspect?key=' . rawurlencode("e2e-$tag:api_token"));
        self::assertStringNotContainsString('tok-should-never-show', $web->body());
        self::assertStringContainsString('hidden', $web->body());

        // session keys: listed with size, never their value
        $web->get('/admin/redis/keys?prefix=sess:');
        preg_match('/runtime-key">(sess:[^<\s]+)/', $web->body(), $m);
        self::assertNotEmpty($m, 'session keys are listed');
        $web->htmx('GET', '/admin/redis/keys/inspect?key=' . rawurlencode($m[1]));
        self::assertStringContainsString('hidden — sensitive key', $web->body());
        self::assertStringNotContainsString('__id', $web->body());

        $web->get('/admin/redis/keys?prefix=' . rawurlencode('sess:*'));
        self::assertStringContainsString('no glob characters', $web->body());
        $this->redis()->del(["cache:e2e-$tag", "e2e-$tag:api_token"]);
    }

    public function testCacheClearNeedsStepUpAndKeepsSessions(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $tag = bin2hex(random_bytes(4));
        $redis = $this->redis();
        $redis->set("cache:e2e-$tag:a", '1');
        $redis->set("cache:e2e-$tag:b", '2');
        $redis->set("sess:e2e-$tag", 'not-a-real-session');

        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $user['id']])->execute();
        $web->get('/admin/redis/keys');
        $web->post('/admin/redis/cache/clear', ['namespace' => "e2e-$tag"]);
        self::assertStringStartsWith('/auth/confirm', $web->url());
        self::assertSame(1, (int) $redis->exists("cache:e2e-$tag:a"));

        AdminSession::reauth($web, $user);
        $web->get('/admin/redis/keys');
        $web->post('/admin/redis/cache/clear', ['namespace' => "e2e-$tag"]);
        self::assertStringContainsString('2 cache key(s) deleted', $web->body());
        self::assertSame(0, (int) $redis->exists("cache:e2e-$tag:a"));
        self::assertSame(1, (int) $redis->exists("sess:e2e-$tag"), 'session namespace untouched');

        // a full cache-namespace clear keeps the operator signed in (sessions are a different namespace)
        $web->post('/admin/redis/cache/clear', ['namespace' => '']);
        self::assertSame(200, $web->get('/admin/redis')->getStatusCode());
        self::assertSame('/admin/redis', $web->url(), 'still authenticated after clearing the cache');
        self::assertSame(1, (int) $redis->exists("sess:e2e-$tag"));

        $web->get('/admin/redis/keys');
        $web->post('/admin/redis/cache/clear', ['namespace' => '*']);
        self::assertStringContainsString('no glob characters', $web->body());
        self::assertTrue((new Query(Fixtures::db()))->from('audit_log')->where(['action' => 'redis.cache.cleared', 'actor_id' => $user['id']])->exists());
        $redis->del(["sess:e2e-$tag"]);
    }

    public function testAuditorCannotClearCache(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/redis/keys')->getStatusCode());
        self::assertStringNotContainsString('/cache/clear', $web->body());
        self::assertSame(403, $web->post('/admin/redis/cache/clear', ['namespace' => ''])->getStatusCode());
    }
}
