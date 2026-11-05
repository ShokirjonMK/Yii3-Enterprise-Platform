<?php

declare(strict_types=1);

namespace App\Admin\Redis;

use App\Platform\Redis\RedisInspector;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Logging\Redactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\User\CurrentUser;

/**
 * 13 · Redis Management — INFO, slowlog, latency, topology, key browser (SCAN, no secret values),
 * cache namespace clear (redis.manage + step-up). Redis being down renders an error state, never fake data.
 */
final class RedisController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RedisInspector $redis,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$role, $redis] = $this->instance($request);
        $ping = $redis->ping();
        $data = ['ping' => $ping, 'topology' => $redis->topology(), 'degraded' => $redis->isCacheDegraded(), 'error' => $ping['error'],
            'role' => $role, 'cacheSeparate' => $this->redis->cacheSeparate()];
        if ($ping['ok']) {
            try {
                $data += [
                    'info' => $redis->info(),
                    'slowlog' => $redis->slowlog(25),
                    'latency' => $redis->latency(),
                    'namespaces' => $redis->namespaceCounts(),
                ];
            } catch (Throwable $e) {
                $data['error'] = Redactor::redactString($e->getMessage());
            }
        }
        return $this->responder->render('@admin/Redis/views/index', $data + ['info' => null, 'slowlog' => [], 'latency' => [], 'namespaces' => []]);
    }

    public function keys(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        [$role, $redis] = $this->instance($request);
        $prefix = trim((string) ($qp['prefix'] ?? ''));
        $cursor = (string) ($qp['cursor'] ?? '0');
        $result = ['keys' => [], 'cursor' => '0', 'scanned_batches' => 0];
        $error = null;
        try {
            $result = $redis->scan($prefix, $cursor, 100);
        } catch (ValidationException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = Redactor::redactString($e->getMessage());
        }
        return $this->responder->render('@admin/Redis/views/keys', [
            'prefix' => $prefix,
            'cursor' => $cursor,
            'result' => $result,
            'error' => $error,
            'appPrefix' => $redis->prefix(),
            'namespaces' => RedisInspector::NAMESPACES,
            'role' => $role,
            'cacheSeparate' => $this->redis->cacheSeparate(),
        ]);
    }

    /** HTMX fragment with metadata (and a preview for non-sensitive string keys only). */
    public function key(ServerRequestInterface $request): ResponseInterface
    {
        $key = (string) ($request->getQueryParams()['key'] ?? '');
        $info = $this->instance($request)[1]->inspect($key) ?? throw new NotFoundException('Key not found (it may have expired).');
        return $this->responder->fragment('@admin/Redis/views/_key', ['k' => $info]);
    }

    public function clearCache(ServerRequestInterface $request): ResponseInterface
    {
        $sub = trim((string) (((array) $request->getParsedBody())['namespace'] ?? ''));
        try {
            $n = $this->redis->clearCache($sub, $this->currentUser->getId());
            $this->responder->success(sprintf('%d cache key(s) deleted from cache:%s*.', $n, $sub));
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.redis.keys', [], ['prefix' => 'cache:'] + ($this->redis->cacheSeparate() ? ['instance' => 'cache'] : []));
    }

    /** ?instance=state|cache → [role, inspector]; "cache" equals "state" when the cache shares the instance. */
    private function instance(ServerRequestInterface $request): array
    {
        $role = ($request->getQueryParams()['instance'] ?? 'state') === 'cache' && $this->redis->cacheSeparate() ? 'cache' : 'state';
        return [$role, $this->redis->instance($role)];
    }
}
