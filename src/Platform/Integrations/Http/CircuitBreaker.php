<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Http;

use App\Platform\Integrations\Exception\CircuitOpenException;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-integration circuit breaker with its state in Redis (shared by all PHP-FPM workers, queue workers and pods).
 *
 *   closed     → calls pass; consecutive failures are counted in a sliding window (max(60 s, 2 × cooldown))
 *   open       → `threshold` failures reached: calls fail fast with CircuitOpenException until `cooldown` elapsed
 *   half-open  → after the cooldown exactly ONE probe call is let through (SET NX token);
 *                success closes the circuit, failure re-opens it for another cooldown.
 *
 * Keys (client prefix applies): integration:cb:<code>:open (unix ts), :failures (counter), :probe (token).
 * Redis outage policy: fail-open (the breaker is a protection, not a dependency) + warning log.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    private const PREFIX = 'integration:cb:';

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @throws CircuitOpenException */
    public function acquire(string $code, int $threshold, int $cooldown): void
    {
        try {
            $openedAt = $this->redis->get($this->key($code, 'open'));
            if ($openedAt === null) {
                return;
            }
            $elapsed = time() - (int) $openedAt;
            if ($elapsed < $cooldown) {
                throw new CircuitOpenException($code, max(1, $cooldown - $elapsed));
            }
            // half-open: a single probe at a time
            $token = $this->redis->set($this->key($code, 'probe'), (string) time(), 'EX', max(5, $cooldown), 'NX');
            if ($token === null) {
                throw new CircuitOpenException($code, 1);
            }
        } catch (CircuitOpenException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger?->warning('Circuit breaker backend unavailable (fail-open)', ['integration' => $code, 'exception' => $e->getMessage()]);
        }
    }

    public function recordSuccess(string $code): void
    {
        try {
            $this->redis->del([$this->key($code, 'open'), $this->key($code, 'failures'), $this->key($code, 'probe')]);
        } catch (Throwable) {
        }
    }

    /** @return string state after recording the failure */
    public function recordFailure(string $code, int $threshold, int $cooldown): string
    {
        try {
            if ($this->redis->get($this->key($code, 'open')) !== null) {
                // probe in half-open failed → open again for another cooldown
                $this->redis->set($this->key($code, 'open'), (string) time());
                $this->redis->del([$this->key($code, 'probe')]);
                return self::OPEN;
            }
            $n = (int) $this->redis->incr($this->key($code, 'failures'));
            if ($n === 1) {
                $this->redis->expire($this->key($code, 'failures'), max(60, 2 * $cooldown));
            }
            if ($n >= $threshold) {
                $this->redis->set($this->key($code, 'open'), (string) time());
                $this->redis->del([$this->key($code, 'probe')]);
                $this->logger?->warning('Circuit breaker opened', ['integration' => $code, 'failures' => $n]);
                return self::OPEN;
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Circuit breaker backend unavailable', ['integration' => $code, 'exception' => $e->getMessage()]);
        }
        return self::CLOSED;
    }

    /** @return array{state:string, failures:int, opened_at:?int, retry_in:int, available:bool} */
    public function state(string $code, int $cooldown): array
    {
        try {
            $openedAt = $this->redis->get($this->key($code, 'open'));
            $failures = (int) $this->redis->get($this->key($code, 'failures'));
        } catch (Throwable) {
            return ['state' => self::CLOSED, 'failures' => 0, 'opened_at' => null, 'retry_in' => 0, 'available' => false];
        }
        if ($openedAt === null) {
            return ['state' => self::CLOSED, 'failures' => $failures, 'opened_at' => null, 'retry_in' => 0, 'available' => true];
        }
        $elapsed = time() - (int) $openedAt;
        return [
            'state' => $elapsed >= $cooldown ? self::HALF_OPEN : self::OPEN,
            'failures' => $failures,
            'opened_at' => (int) $openedAt,
            'retry_in' => max(0, $cooldown - $elapsed),
            'available' => true,
        ];
    }

    public function reset(string $code): void
    {
        $this->recordSuccess($code);
    }

    private function key(string $code, string $part): string
    {
        return self::PREFIX . $code . ':' . $part;
    }
}
