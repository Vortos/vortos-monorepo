<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;

final class RedisRateLimitStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisRateLimitStore $store;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisRateLimitStore($this->redis);
    }

    public function test_increment_returns_count(): void
    {
        $this->redis->method('eval')->willReturn(1);
        $result = $this->store->increment('key', 60);
        $this->assertSame(1, $result);
    }

    public function test_increment_sets_ttl_on_first_call(): void
    {
        $this->redis->expects($this->once())->method('eval')
            ->with($this->anything(), ['key', '60'], 1)
            ->willReturn(1);
        $this->store->increment('key', 60);
    }

    public function test_increment_does_not_reset_ttl_on_subsequent_calls(): void
    {
        $this->redis->method('eval')->willReturn(5);
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

    public function test_increment_throws_typed_exception_on_redis_failure(): void
    {
        $this->redis->method('eval')->willThrowException(new \RedisException('Connection refused'));

        $this->expectException(RateLimitStoreUnavailableException::class);
        $this->store->increment('key', 60);
    }

    public function test_increment_throws_typed_exception_on_any_throwable(): void
    {
        $this->redis->method('eval')->willThrowException(new \Error('phpredis internal error'));

        $this->expectException(RateLimitStoreUnavailableException::class);
        $this->store->increment('key', 60);
    }

    public function test_get_ttl_throws_typed_exception_on_redis_failure(): void
    {
        $this->redis->method('ttl')->willThrowException(new \RedisException('Connection refused'));

        $this->expectException(RateLimitStoreUnavailableException::class);
        $this->store->getTtl('key');
    }

    public function test_reset_throws_typed_exception_on_redis_failure(): void
    {
        $this->redis->method('del')->willThrowException(new \RedisException('Connection refused'));

        $this->expectException(RateLimitStoreUnavailableException::class);
        $this->store->reset('key');
    }

    public function test_exception_preserves_original_as_previous(): void
    {
        $original = new \RedisException('Connection refused');
        $this->redis->method('eval')->willThrowException($original);

        try {
            $this->store->increment('key', 60);
            $this->fail('Expected exception');
        } catch (RateLimitStoreUnavailableException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }
}
