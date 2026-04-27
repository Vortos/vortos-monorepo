<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout\Storage;

use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;

final class RedisLockoutStore implements LockoutStoreInterface
{
    public function __construct(private \Redis $redis) {}

    public function incrementAttempts(string $type, string $value, int $windowSeconds): int
    {
        $key = "lockout:attempts:{$type}:{$value}";
        $count = $this->redis->incrBy($key, 1);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        return $count;
    }

    public function lock(string $type, string $value, int $durationSeconds): void
    {
        $this->redis->setEx("lockout:locked:{$type}:{$value}", $durationSeconds, '1');
    }

    public function isLocked(string $type, string $value): bool
    {
        return (bool) $this->redis->exists("lockout:locked:{$type}:{$value}");
    }

    public function getRemainingTtl(string $type, string $value): int
    {
        return max(0, $this->redis->ttl("lockout:locked:{$type}:{$value}"));
    }

    public function clearAttempts(string $type, string $value): void
    {
        $this->redis->del("lockout:attempts:{$type}:{$value}");
        $this->redis->del("lockout:locked:{$type}:{$value}");
    }
}
