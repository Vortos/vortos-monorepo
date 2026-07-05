<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\State;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\RedisEdgeStateStore;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-D (D4): the Redis control-plane edge-state store. The fail-closed behaviour (no Redis client)
 * is asserted always; the round-trip is asserted with an in-memory ext-redis stand-in when the
 * extension is present.
 */
final class RedisEdgeStateStoreTest extends TestCase
{
    public function test_missing_redis_client_fails_closed_with_actionable_message(): void
    {
        $store = new RedisEdgeStateStore(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EDGE_STATE_STORE=file');
        $store->load('production');
    }

    public function test_round_trip_and_monotonic_version(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not installed');
        }

        $store = new RedisEdgeStateStore($this->inMemoryRedis());

        $first = $store->save($this->state(ActiveColor::Blue, 'api.example.com'));
        self::assertSame(1, $first->version);

        $second = $store->save($this->state(ActiveColor::Green, 'api.example.com'));
        self::assertSame(2, $second->version);

        $loaded = $store->load('production');
        self::assertNotNull($loaded);
        self::assertSame(ActiveColor::Green, $loaded->activeColor);
        self::assertSame('api.example.com', $loaded->domain);
    }

    public function test_unknown_env_loads_null(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not installed');
        }

        self::assertNull((new RedisEdgeStateStore($this->inMemoryRedis()))->load('never'));
    }

    private function state(ActiveColor $color, string $domain): EdgeState
    {
        return new EdgeState(
            env: 'production',
            activeColor: $color,
            upstreamHost: 'app-' . $color->value,
            upstreamPort: 8080,
            domain: $domain,
        );
    }

    /** An in-memory ext-redis stand-in covering the get/set/incr surface the store uses. */
    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function __construct()
            {
                // Skip the real ext-redis constructor (no server connection in tests).
            }

            #[\ReturnTypeWillChange]
            public function get($key): string|false
            {
                return $this->store[$key] ?? false;
            }

            #[\ReturnTypeWillChange]
            public function set($key, $value, $options = null): \Redis|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }

            #[\ReturnTypeWillChange]
            public function incr($key, $by = 1): \Redis|int|false
            {
                $next = (int) ($this->store[$key] ?? 0) + 1;
                $this->store[$key] = (string) $next;

                return $next;
            }
        };
    }
}
