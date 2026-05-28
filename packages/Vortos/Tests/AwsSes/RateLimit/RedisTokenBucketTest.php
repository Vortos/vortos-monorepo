<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\RateLimit\RedisTokenBucket;

/**
 * Tests for RedisTokenBucket.
 *
 * We stub \Redis::eval() rather than hitting a real Redis instance.
 * The Lua script itself is covered by the integration suite.
 */
final class RedisTokenBucketTest extends TestCase
{
    private function makeRedis(int $evalReturn): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn($evalReturn);
        return $redis;
    }

    public function test_try_consume_returns_true_when_lua_returns_1(): void
    {
        $bucket = new RedisTokenBucket($this->makeRedis(1), maxRate: 14, burst: 14);

        $this->assertTrue($bucket->tryConsume());
    }

    public function test_try_consume_returns_false_when_lua_returns_0(): void
    {
        $bucket = new RedisTokenBucket($this->makeRedis(0), maxRate: 14, burst: 14);

        $this->assertFalse($bucket->tryConsume());
    }

    public function test_lua_is_called_with_two_key_arguments(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->anything(),          // script string
                $this->callback(function (array $args): bool {
                    // KEYS[1] and KEYS[2] are the first two elements, numkeys = 2
                    return str_contains($args[0], ':tokens')
                        && str_contains($args[1], ':ts');
                }),
                2,                          // numkeys
            )
            ->willReturn(1);

        $bucket = new RedisTokenBucket($redis, maxRate: 10, burst: 10, keyPrefix: 'test_bucket');
        $bucket->tryConsume();
    }

    public function test_key_prefix_is_used_in_redis_keys(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->anything(),
                $this->callback(function (array $args): bool {
                    return $args[0] === 'custom_prefix:tokens'
                        && $args[1] === 'custom_prefix:ts';
                }),
                2,
            )
            ->willReturn(1);

        $bucket = new RedisTokenBucket($redis, maxRate: 5, burst: 5, keyPrefix: 'custom_prefix');
        $bucket->tryConsume();
    }

    public function test_argv_contains_rate_burst_timestamp_and_ttl(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->anything(),
                $this->callback(function (array $args): bool {
                    // ARGV: rate=7, burst=20, now (float string), ttl (int string)
                    return (string) 7  === $args[2]
                        && (string) 20 === $args[3]
                        && is_numeric($args[4])   // microtime(true)
                        && is_numeric($args[5]);   // keyTtl
                }),
                2,
            )
            ->willReturn(1);

        $bucket = new RedisTokenBucket($redis, maxRate: 7, burst: 20);
        $bucket->tryConsume();
    }

    public function test_key_ttl_is_at_least_60_seconds(): void
    {
        // burst=1, rate=100 → raw ttl = ceil(1/100*2) = 1 → clamped to 60
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->anything(),
                $this->callback(function (array $args): bool {
                    return (int) $args[5] >= 60;
                }),
                2,
            )
            ->willReturn(1);

        $bucket = new RedisTokenBucket($redis, maxRate: 100, burst: 1);
        $bucket->tryConsume();
    }
}
