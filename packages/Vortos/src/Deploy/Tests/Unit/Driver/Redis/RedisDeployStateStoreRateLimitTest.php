<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\Redis;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\RateLimitStateStoreInterface;
use Vortos\Deploy\Driver\Redis\RedisDeployStateStore;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;

/**
 * GAP-I: the durable Redis deploy-state store must also implement the reconcile rate-limit port so
 * the ENTIRE control plane (release, soak, freshness, rate-limit) shares one durable store. Before
 * this fix only the file driver implemented it, so repointing the alias to redis would have crashed
 * container compilation. Round-trip verified against an in-memory ext-redis stand-in.
 */
final class RedisDeployStateStoreRateLimitTest extends TestCase
{
    public function test_implements_all_four_control_plane_ports(): void
    {
        $store = new RedisDeployStateStore($this->fakeRedis());

        self::assertInstanceOf(RateLimitStateStoreInterface::class, $store);
        self::assertInstanceOf(ManifestFreshnessStoreInterface::class, $store);
    }

    public function test_unknown_env_has_no_timestamp(): void
    {
        $store = new RedisDeployStateStore($this->fakeRedis());

        self::assertNull($store->loadLastReloadTimestamp('production'));
    }

    public function test_save_then_load_round_trips_with_microsecond_precision(): void
    {
        $store = new RedisDeployStateStore($this->fakeRedis());

        $ts = 1751800000.123456;
        $store->saveLastReloadTimestamp('production', $ts);

        self::assertSame($ts, $store->loadLastReloadTimestamp('production'));
    }

    public function test_save_is_monotonic_and_never_rewinds(): void
    {
        $store = new RedisDeployStateStore($this->fakeRedis());

        $store->saveLastReloadTimestamp('production', 2000.0);
        // A slower node writing an older timestamp must NOT rewind the gate.
        $store->saveLastReloadTimestamp('production', 1000.0);

        self::assertSame(2000.0, $store->loadLastReloadTimestamp('production'));

        // A newer timestamp advances it.
        $store->saveLastReloadTimestamp('production', 3000.0);
        self::assertSame(3000.0, $store->loadLastReloadTimestamp('production'));
    }

    public function test_envs_are_isolated(): void
    {
        $store = new RedisDeployStateStore($this->fakeRedis());

        $store->saveLastReloadTimestamp('production', 100.0);
        $store->saveLastReloadTimestamp('staging', 200.0);

        self::assertSame(100.0, $store->loadLastReloadTimestamp('production'));
        self::assertSame(200.0, $store->loadLastReloadTimestamp('staging'));
    }

    /** In-memory ext-redis stand-in covering the get/set/watch/unwatch/multi/exec surface used. */
    private function fakeRedis(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not installed');
        }

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
            public function watch($key, string ...$other_keys): \Redis|bool
            {
                return true;
            }

            #[\ReturnTypeWillChange]
            public function unwatch(): \Redis|bool
            {
                return true;
            }

            #[\ReturnTypeWillChange]
            public function multi(int $value = \Redis::MULTI): \Redis|bool
            {
                return $this;
            }

            #[\ReturnTypeWillChange]
            public function exec(): \Redis|array|false
            {
                return [];
            }
        };
    }
}
