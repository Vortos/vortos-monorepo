<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;

final class RedisRateLimitStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisRateLimitStore $store;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisRateLimitStore($this->redis);
    }

    public function test_increment_returns_count(): void
    {
        $this->redis->method('incrBy')->willReturn(1);
        $this->redis->method('expire');
        $result = $this->store->increment('key', 60);
        $this->assertSame(1, $result);
    }

    public function test_increment_sets_ttl_on_first_call(): void
    {
        $this->redis->method('incrBy')->willReturn(1);
        $this->redis->expects($this->once())->method('expire')->with('key', 60);
        $this->store->increment('key', 60);
    }

    public function test_increment_does_not_reset_ttl_on_subsequent_calls(): void
    {
        $this->redis->method('incrBy')->willReturn(5);
        $this->redis->expects($this->never())->method('expire');
        $this->store->increment('key', 60);
    }

    public function test_get_ttl(): void
    {
        $this->redis->method('ttl')->willReturn(45);
        $this->assertSame(45, $this->store->getTtl('key'));
    }

    public function test_get_ttl_returns_zero_for_expired(): void
    {
        $this->redis->method('ttl')->willReturn(-1);
        $this->assertSame(0, $this->store->getTtl('key'));
    }

    public function test_reset_deletes_key(): void
    {
        $this->redis->expects($this->once())->method('del')->with('key');
        $this->store->reset('key');
    }
}
