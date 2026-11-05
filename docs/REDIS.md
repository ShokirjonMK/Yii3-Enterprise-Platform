# Redis

Redis is never the system of record: everything in it is either recomputable (cache) or short-lived coordination state.
The platform uses it in two roles that have different failure and eviction rules.

| Role | Keys (`REDIS_PREFIX` = `yep:`) | Eviction | Persistence | Outage policy |
|---|---|---|---|---|
| **state** (`REDIS_*`) | `sess:` sessions, `lock:` distributed locks, `rl:` rate limits, OTP codes, Telegram link codes, config revision, circuit breakers, metrics | **`noeviction`** | AOF everysec + RDB | Sessions fail **closed** (503, never per-node fallback); locks/rate limits fail safe |
| **cache** (`REDIS_CACHE_*`) | `cache:` PSR-16 application cache | **`allkeys-lfu`** | none | Fail **open**: errors are logged, then calls bypass Redis for 10 s; data comes from PostgreSQL |

## Why two roles

With one instance and an LRU/LFU policy, a full instance deletes *any* key, including session keys.
- **Users are signed out at random**, typically under load, and look as if their session expired too early.
- **Locks disappear**, so two schedulers may run the same job.
- **One-time codes vanish.**

With `noeviction`, a full instance refuses writes instead, and the monitoring alerts fire long before that.

The cache must evict, so it gets its own instance.

`REDIS_CACHE_DEFAULT_TTL` (default 86400 s) applies to cache entries written without a TTL. If the cache shares the
state instance, it therefore cannot grow without bound.

## Configuration (`.env`)

```dotenv
# state
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=...
REDIS_DB=0
REDIS_USERNAME=               # ACL user (Redis 6+)
REDIS_TLS=false               # or REDIS_URL=rediss://user:pass@host:6380/0
REDIS_TLS_CA=                 # CA bundle for a private CA
REDIS_MODE=standalone         # standalone | sentinel | cluster
REDIS_SENTINELS=s1:26379,s2:26379,s3:26379
REDIS_SENTINEL_SERVICE=mymaster
REDIS_SENTINEL_PASSWORD=      # Sentinel's own requirepass
REDIS_CONNECT_TIMEOUT=1.5
REDIS_READ_TIMEOUT=3
REDIS_PERSISTENT=true         # PHP-FPM: one socket per role/database per worker

# cache (empty REDIS_CACHE_HOST = shares the state instance)
REDIS_CACHE_HOST=redis-cache
REDIS_CACHE_PORT=6379
REDIS_CACHE_DB=0
REDIS_CACHE_PASSWORD=         # default: REDIS_PASSWORD
REDIS_CACHE_DEFAULT_TTL=86400
# also: REDIS_CACHE_URL, REDIS_CACHE_USERNAME, REDIS_CACHE_TLS, REDIS_CACHE_MODE, REDIS_CACHE_SENTINELS
```

**Persistent connections.** In PHP-FPM they avoid a TCP handshake and `AUTH` on every request. The persistent id
contains the role and the database number, so two clients that `SELECT` different databases never share a socket.
CLI workers always use regular connections.

## Server configuration

`infra/docker/redis/redis.conf` (state) and `redis-cache.conf` (cache) are the development baseline. For production,
keep the same policies and set the following:

- **State:**
  - `maxmemory`: about 70 % of the instance RAM;
  - `maxmemory-policy noeviction`;
  - `appendonly yes`;
  - Sentinel or a managed HA Redis, with 3 Sentinels on separate hosts.
- **Cache:** `allkeys-lfu`, no persistence. A replica is optional; a cold cache is acceptable.
- **Both:**
  - `requirepass` or ACL users with the minimum command set, TLS, `protected-mode yes`;
  - `FLUSHALL`, `FLUSHDB` and `DEBUG` renamed off. The platform clears its cache with SCAN + UNLINK inside `cache:` only;
  - `lazyfree-*` on, `tcp-keepalive 60`.
- **Cluster mode:** Redis Cluster has no databases, so the platform uses database 0 and separates data with the
  prefix.

## Admin → Redis Management

**Overview.** When the cache is separate, a sub-tab switches between the state and cache instances.

| Element | What it shows |
|---|---|
| Overview | `INFO` sections, memory, hit rate, slowlog, latency |
| Topology | Role, TLS, ACL user, persistent connections |
| Eviction warning | Shown when the state instance uses an evicting policy |

**Keys & cache:**
- SCAN-based key browser. Values of sensitive keys (sessions, locks, tokens) are never shown.
- Clearing the cache namespace is always done on the cache instance and requires step-up confirmation.

**Health.** `/health/ready` and the dashboard report:
- `redis`: critical. Includes `maxmemory_policy`, and a warning when the policy evicts.
- `redis_cache`: not critical, because the cache is fail-open.

Tests: `tests/Integration/RedisRolesTest.php`, `tests/Integration/RuntimeInspectorsTest.php`, `tests/E2E/Admin/RedisAdminTest.php`.
