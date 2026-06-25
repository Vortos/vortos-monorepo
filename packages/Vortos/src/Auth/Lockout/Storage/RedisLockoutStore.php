<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout\Storage;

use Vortos\Auth\Lockout\CircuitBreaker\LockoutCircuitBreaker;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;
use Vortos\Auth\Lockout\LockoutCheckResult;
use Vortos\Auth\Lockout\Exception\LockoutUnavailableException;

final class RedisLockoutStore implements LockoutStoreInterface
{
    public function __construct(
        private \Redis $redis,
        private LockoutCircuitBreaker $circuitBreaker = new LockoutCircuitBreaker(),
    ) {}

    public function incrementAttempts(string $type, string $value, int $windowSeconds): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return -1;
        }

        $key = "lockout:attempts:{$type}:{$value}";
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
            $this->redis->setEx("lockout:locked:{$type}:{$value}", $durationSeconds, '1');
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
            $result = (bool) $this->redis->exists("lockout:locked:{$type}:{$value}");
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
            $result = (int) ($this->redis->get("lockout:attempts:{$type}:{$value}") ?: 0);
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
            $result = max(0, $this->redis->ttl("lockout:locked:{$type}:{$value}"));
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
            $this->redis->del("lockout:attempts:{$type}:{$value}");
            $this->redis->del("lockout:locked:{$type}:{$value}");
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();
        }
    }
}
