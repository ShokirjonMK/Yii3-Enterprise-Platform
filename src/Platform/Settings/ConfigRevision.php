<?php

declare(strict_types=1);

namespace App\Platform\Settings;

use Predis\ClientInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Cluster-wide configuration revision used to invalidate per-process caches of settings and flags.
 *
 *  write:  PostgreSQL `config_revision` is incremented (source of truth) and the value is pushed to Redis.
 *  read:   APCu (≤ 2 s) → Redis key → PostgreSQL fallback when Redis is unavailable.
 *
 * Consequence: a change becomes visible on every PHP instance within ~2 seconds, and still within
 * a few seconds when Redis is down (version check against PostgreSQL).
 */
final class ConfigRevision
{
    private const REDIS_KEY = 'config:revision';
    private const APCU_KEY = 'yep:config:revision';
    private const LOCAL_TTL = 2;

    private ?int $requestRevision = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClientInterface $redis,
    ) {}

    public function current(): int
    {
        if ($this->requestRevision !== null) {
            return $this->requestRevision;
        }
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $v = apcu_fetch(self::APCU_KEY, $ok);
            if ($ok) {
                return $this->requestRevision = (int) $v;
            }
        }
        $rev = null;
        try {
            $v = $this->redis->get(self::REDIS_KEY);
            $rev = $v === null ? null : (int) $v;
        } catch (Throwable) {
        }
        if ($rev === null) {
            $rev = (int) $this->db->createCommand('SELECT revision FROM config_revision WHERE id = 1')->queryScalar();
            try {
                $this->redis->set(self::REDIS_KEY, (string) $rev);
            } catch (Throwable) {
            }
        }
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store(self::APCU_KEY, $rev, self::LOCAL_TTL);
        }
        return $this->requestRevision = $rev;
    }

    public function bump(): int
    {
        $rev = (int) $this->db->createCommand('UPDATE config_revision SET revision = revision + 1, changed_at = now() WHERE id = 1 RETURNING revision')->queryScalar();
        try {
            $this->redis->set(self::REDIS_KEY, (string) $rev);
        } catch (Throwable) {
            // readers fall back to PostgreSQL; nothing is lost
        }
        if (function_exists('apcu_delete')) {
            apcu_delete(self::APCU_KEY);
        }
        $this->requestRevision = null;
        return $rev;
    }

    public function reset(): void
    {
        $this->requestRevision = null;
    }
}
