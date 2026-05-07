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
    private ?string $previousDsn;
    private ?string $previousPrefix;
    private ?string $previousAppEnv;
    private ?string $previousAppName;

    protected function setUp(): void
    {
        $this->previousDriver = $_ENV['VORTOS_CACHE_DRIVER'] ?? null;
        $this->previousDsn = $_ENV['VORTOS_CACHE_DSN'] ?? null;
        $this->previousPrefix = $_ENV['VORTOS_CACHE_PREFIX'] ?? null;
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->previousAppName = $_ENV['APP_NAME'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('VORTOS_CACHE_DRIVER', $this->previousDriver);
        $this->restoreEnv('VORTOS_CACHE_DSN', $this->previousDsn);
        $this->restoreEnv('VORTOS_CACHE_PREFIX', $this->previousPrefix);
        $this->restoreEnv('APP_ENV', $this->previousAppEnv);
        $this->restoreEnv('APP_NAME', $this->previousAppName);
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

    public function test_uses_env_connection_defaults(): void
    {
        $_ENV['VORTOS_CACHE_DSN'] = 'redis://redis:6380';
        $_ENV['VORTOS_CACHE_PREFIX'] = 'dev_demo_';

        $config = (new VortosCacheConfig())->toArray();

        $this->assertSame('redis://redis:6380', $config['dsn']);
        $this->assertSame('dev_demo_', $config['prefix']);
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            return;
        }

        $_ENV[$key] = $value;
    }
}
