<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\Storage\RedisLockoutStore;

final class RedisLockoutStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisLockoutStore $store;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisLockoutStore($this->redis);
    }

    public function test_increment_attempts_returns_count(): void
    {
        $this->redis->method('incrBy')->willReturn(3);
        $result = $this->store->incrementAttempts('email', 'user@example.com', 900);
        $this->assertSame(3, $result);
    }

    public function test_increment_sets_ttl_on_first_attempt(): void
    {
        $this->redis->method('incrBy')->willReturn(1);
        $this->redis->expects($this->once())->method('expire');
        $this->store->incrementAttempts('email', 'user@example.com', 900);
    }

    public function test_lock_sets_redis_key_with_ttl(): void
    {
        $this->redis->expects($this->once())->method('setEx')
            ->with('lockout:locked:email:user@example.com', 900, '1');
        $this->store->lock('email', 'user@example.com', 900);
    }

    public function test_is_locked_returns_true_when_key_exists(): void
    {
        $this->redis->method('exists')->willReturn(1);
        $this->assertTrue($this->store->isLocked('email', 'user@example.com'));
    }

    public function test_is_locked_returns_false_when_key_missing(): void
    {
        $this->redis->method('exists')->willReturn(0);
        $this->assertFalse($this->store->isLocked('email', 'user@example.com'));
    }

    public function test_get_remaining_ttl(): void
    {
        $this->redis->method('ttl')->willReturn(450);
        $this->assertSame(450, $this->store->getRemainingTtl('email', 'user@example.com'));
    }

    public function test_clear_attempts_deletes_both_keys(): void
    {
        $this->redis->expects($this->exactly(2))->method('del');
        $this->store->clearAttempts('email', 'user@example.com');
    }
}
