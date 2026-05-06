<?php

declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\DependencyInjection\VortosCacheConfig;

final class VortosCacheConfigTest extends TestCase
{
    private ?string $previousDriver;

    protected function setUp(): void
    {
        $this->previousDriver = $_ENV['VORTOS_CACHE_DRIVER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousDriver === null) {
            unset($_ENV['VORTOS_CACHE_DRIVER']);
            return;
        }

        $_ENV['VORTOS_CACHE_DRIVER'] = $this->previousDriver;
    }

    public function test_defaults_to_in_memory_without_env_choice(): void
    {
        unset($_ENV['VORTOS_CACHE_DRIVER']);

        $this->assertSame(InMemoryAdapter::class, (new VortosCacheConfig())->toArray()['driver']);
    }

    public function test_uses_redis_when_env_selects_redis(): void
    {
        $_ENV['VORTOS_CACHE_DRIVER'] = 'redis';

        $this->assertSame(RedisAdapter::class, (new VortosCacheConfig())->toArray()['driver']);
    }
}
