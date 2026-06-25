<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutCheckResult;
use Vortos\Auth\Lockout\Storage\RedisLockoutStore;

final class RedisLockoutStoreResilienceTest extends TestCase
{
    private \Redis $redis;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->redis = $this->createMock(\Redis::class);
    }

    public function test_is_locked_returns_unavailable_on_redis_failure(): void
    {
        $this->redis->method('exists')->willThrowException(new \RedisException('Connection lost'));
        $store = new RedisLockoutStore($this->redis);

        $result = $store->isLocked('email', 'user@example.com');

        $this->assertTrue($result->unavailable);
        $this->assertFalse($result->locked);
    }

    public function test_is_locked_returns_locked_on_success(): void
    {
        $this->redis->method('exists')->willReturn(1);
        $store = new RedisLockoutStore($this->redis);

        $result = $store->isLocked('email', 'user@example.com');

        $this->assertFalse($result->unavailable);
        $this->assertTrue($result->locked);
    }

    public function test_is_locked_returns_not_locked_on_success(): void
    {
        $this->redis->method('exists')->willReturn(0);
        $store = new RedisLockoutStore($this->redis);

        $result = $store->isLocked('email', 'user@example.com');

        $this->assertFalse($result->unavailable);
        $this->assertFalse($result->locked);
    }

    public function test_increment_returns_negative_one_on_redis_failure(): void
    {
        $this->redis->method('eval')->willThrowException(new \RedisException('Connection lost'));
        $store = new RedisLockoutStore($this->redis);

        $this->assertSame(-1, $store->incrementAttempts('email', 'user@example.com', 900));
    }

    public function test_lock_does_not_throw_on_redis_failure(): void
    {
        $this->redis->method('setEx')->willThrowException(new \RedisException('Connection lost'));
        $store = new RedisLockoutStore($this->redis);

        $store->lock('email', 'user@example.com', 900);
        $this->assertTrue(true);
    }

    public function test_clear_attempts_does_not_throw_on_redis_failure(): void
    {
        $this->redis->method('del')->willThrowException(new \RedisException('Connection lost'));
        $store = new RedisLockoutStore($this->redis);

        $store->clearAttempts('email', 'user@example.com');
        $this->assertTrue(true);
    }

    public function test_get_remaining_ttl_returns_zero_on_redis_failure(): void
    {
        $this->redis->method('ttl')->willThrowException(new \RedisException('Connection lost'));
        $store = new RedisLockoutStore($this->redis);

        $this->assertSame(0, $store->getRemainingTtl('email', 'user@example.com'));
    }
}
