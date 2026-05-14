<?php

declare(strict_types=1);

namespace Vortos\Cache\DependencyInjection;

use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\Adapter\InMemoryAdapter;

/**
 * Fluent configuration object for vortos-cache.
 *
 * Loaded via require in CacheExtension::load().
 * Every setting has a sensible default — no config file required for basic usage.
 *
 * ## Standard usage
 *
 * Create config/cache.php in your project:
 *
 *   return static function(VortosCacheConfig $config): void {
 *       $config
 *           ->dsn($_ENV['VORTOS_CACHE_DSN'])
 *           ->prefix(getenv('APP_ENV') . '_squaura_')
 *           ->defaultTtl(3600);
 *   };
 *
 * ## Swapping the driver (e.g. for testing)
 *
 *   // config/test/cache.php
 *   return static function(VortosCacheConfig $config): void {
 *       $config->driver(InMemoryAdapter::class);
 *   };
 *
 * The CacheInterface and TaggedCacheInterface aliases both point to the
 * configured driver — everything that injects either interface gets the new one.
 *
 * ## Key prefix
 *
 * Always include APP_ENV in the prefix.
 * 'dev_squaura_' and 'prod_squaura_' can safely share one Redis instance
 * without key collision. This is standard practice for shared Redis setups.
 */
final class VortosCacheConfig
{
    private string $driver;
    private string $dsn = 'redis://redis:6379';
    private string $prefix = 'vortos_';
    private int $defaultTtl = 3600;
    /** @var list<class-string> */
    private array $allowedClasses = [];

    public function __construct()
    {
        $this->driver = match ($_ENV['VORTOS_CACHE_DRIVER'] ?? 'in-memory') {
            'redis' => RedisAdapter::class,
            default => InMemoryAdapter::class,
        };
        $this->dsn = $_ENV['VORTOS_CACHE_DSN'] ?? 'redis://127.0.0.1:6379';
        $this->prefix = $_ENV['VORTOS_CACHE_PREFIX'] ?? (($_ENV['APP_ENV'] ?? 'dev') . '_' . ($_ENV['APP_NAME'] ?? 'app') . '_');
    }

    /**
     * Set the cache adapter driver.
     *
     * Must be a FQCN implementing TaggedCacheInterface.
     * Default: RedisAdapter::class
     *
     * @param class-string<\Vortos\Cache\Contract\TaggedCacheInterface> $adapterClass
     */
    public function driver(string $adapterClass): static
    {
        $this->driver = $adapterClass;
        return $this;
    }

    /**
     * Set the connection DSN for the cache driver.
     *
     * For Redis: redis://[:password@]host[:port][/database]
     * Ignored when driver is InMemoryAdapter or ArrayAdapter.
     */
    public function dsn(string $dsn): static
    {
        $this->dsn = $dsn;
        return $this;
    }

    /**
     * Set the key prefix applied to all cache keys.
     *
     * Include APP_ENV and app name: getenv('APP_ENV') . '_squaura_'
     * This prevents key collisions between environments on a shared Redis instance.
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Set the default TTL in seconds for keys stored without an explicit TTL.
     *
     * Default: 3600 (1 hour).
     * Set to 0 for no expiry by default — not recommended for Redis (memory growth).
     */
    public function defaultTtl(int $seconds): static
    {
        $this->defaultTtl = $seconds;
        return $this;
    }

    /**
     * Allow specific classes to be instantiated when deserializing cached objects.
     *
     * By default, only scalars and arrays can be deserialized from Redis. Pass the
     * FQCNs of value objects or DTOs you intentionally cache as PHP objects.
     *
     * @param list<class-string> $classes
     */
    public function allowSerializedClasses(array $classes): static
    {
        $this->allowedClasses = $classes;
        return $this;
    }

    /** @internal Used by CacheExtension */
    public function toArray(): array
    {
        return [
            'driver'          => $this->driver,
            'dsn'             => $this->dsn,
            'prefix'          => $this->prefix,
            'default_ttl'     => $this->defaultTtl,
            'allowed_classes' => $this->allowedClasses,
        ];
    }
}
