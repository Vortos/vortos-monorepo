<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout\Storage;

use Vortos\Auth\Lockout\CircuitBreaker\LockoutCircuitBreaker;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;
use Vortos\Auth\Lockout\LockoutCheckResult;
use Vortos\Auth\Lockout\Exception\LockoutUnavailableException;

final class RedisLockoutStore implements LockoutStoreInterface
{
    /**
     * @param string $namespace Key prefix. Two stores on one Redis with the same namespace share every
     *                          counter and lock, and one manager's clearLockout() on a successful sign-in
     *                          wipes the other's. A second policy (a stricter one for privileged accounts,
     *                          or a per-address failure counter that success must never reset) needs its own.
     */
    public function __construct(
        private \Redis $redis,
        private LockoutCircuitBreaker $circuitBreaker = new LockoutCircuitBreaker(),
        private string $namespace = 'lockout',
    ) {
        if (preg_match('/^[a-z0-9_]+(?::[a-z0-9_]+)*$/', $namespace) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Lockout namespace "%s" must be lowercase words joined by colons, e.g. lockout:staff.',
                $namespace,
            ));
        }
    }

    private function key(string $kind, string $type, string $value): string
    {
        return "{$this->namespace}:{$kind}:{$type}:{$value}";
    }

    public function incrementAttempts(string $type, string $value, int $windowSeconds): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return -1;
        }

        $key = $this->key('attempts', $type, $value);
        $script = <<<'LUA'
local current = redis.call('INCRBY', KEYS[1], 1)
if current == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
return current
LUA;
        try {
            $result = (int) $this->redis->eval($script, [$key, (string) $windowSeconds], 1);
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
            return -1;
        }
    }

    public function lock(string $type, string $value, int $durationSeconds): void
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return;
        }

        try {
            $this->redis->setEx($this->key('locked', $type, $value), $durationSeconds, '1');
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
        }
    }

    public function isLocked(string $type, string $value): LockoutCheckResult
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return LockoutCheckResult::unavailable();
        }

        try {
            $result = (bool) $this->redis->exists($this->key('locked', $type, $value));
            $this->circuitBreaker->recordSuccess();
            return $result ? LockoutCheckResult::locked() : LockoutCheckResult::notLocked();
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
            return LockoutCheckResult::unavailable();
        }
    }

    public function getAttemptCount(string $type, string $value): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return -1;
        }

        try {
            $result = (int) ($this->redis->get($this->key('attempts', $type, $value)) ?: 0);
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
            return -1;
        }
    }

    public function getRemainingTtl(string $type, string $value): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return 0;
        }

        try {
            $result = max(0, $this->redis->ttl($this->key('locked', $type, $value)));
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
            return 0;
        }
    }

    public function clearAttempts(string $type, string $value): void
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return;
        }

        try {
            $this->redis->del($this->key('attempts', $type, $value));
            $this->redis->del($this->key('locked', $type, $value));
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
        }
    }
}
