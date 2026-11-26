<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

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
        $this->redis->method('eval')->willReturn(3);
        $result = $this->store->incrementAttempts('email', 'user@example.com', 900);
        $this->assertSame(3, $result);
    }

    public function test_increment_sets_ttl_on_first_attempt(): void
    {
        $this->redis->expects($this->once())->method('eval')
            ->with($this->anything(), ['lockout:attempts:email:user@example.com', '900'], 1)
            ->willReturn(1);
        $this->store->incrementAttempts('email', 'user@example.com', 900);
    }

    public function test_a_namespaced_store_keeps_its_own_counters_and_locks(): void
    {
        $redis = $this->createMock(\Redis::class);
        $store = new RedisLockoutStore($redis, namespace: 'lockout:auth_ip');

        $redis->expects($this->once())->method('eval')
            ->with($this->anything(), ['lockout:auth_ip:attempts:ip:203.0.113.9', '900'], 1)
            ->willReturn(1);
        $redis->expects($this->once())->method('setEx')
            ->with('lockout:auth_ip:locked:ip:203.0.113.9', 900, '1');
        $deleted = [];
        $redis->method('del')->willReturnCallback(static function (string $key) use (&$deleted): int {
            $deleted[] = $key;
            return 1;
        });

        $store->incrementAttempts('ip', '203.0.113.9', 900);
        $store->lock('ip', '203.0.113.9', 900);
        $store->clearAttempts('ip', '203.0.113.9');

        // Clearing touches only this namespace: the default store's keys are never named.
        $this->assertSame(['lockout:auth_ip:attempts:ip:203.0.113.9', 'lockout:auth_ip:locked:ip:203.0.113.9'], $deleted);
    }

    public function test_a_namespace_that_could_collide_or_inject_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisLockoutStore($this->redis, namespace: 'Lockout:*');
    }

    public function test_lock_sets_redis_key_with_ttl(): void
    {
        $this->redis->expects($this->once())->method('setEx')
            ->with('lockout:locked:email:user@example.com', 900, '1');
        $this->store->lock('email', 'user@example.com', 900);
    }

    public function test_is_locked_returns_locked_when_key_exists(): void
    {
        $this->redis->method('exists')->willReturn(1);
        $result = $this->store->isLocked('email', 'user@example.com');
        $this->assertTrue($result->locked);
        $this->assertFalse($result->unavailable);
    }

    public function test_is_locked_returns_not_locked_when_key_missing(): void
    {
        $this->redis->method('exists')->willReturn(0);
        $result = $this->store->isLocked('email', 'user@example.com');
        $this->assertFalse($result->locked);
        $this->assertFalse($result->unavailable);
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
