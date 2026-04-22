<?php

declare(strict_types=1);

namespace Vortos\Cache\DependencyInjection;

use Vortos\Cache\Adapter\RedisAdapter;

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
 *           ->dsn(sprintf('redis://%s:%s', getenv('REDIS_HOST'), getenv('REDIS_PORT')))
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
    private string $driver = RedisAdapter::class;
    private string $dsn = 'redis://redis:6379';
    private string $prefix = 'vortos_';
    private int $defaultTtl = 3600;

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

    /** @internal Used by CacheExtension */
    public function toArray(): array
    {
        return [
            'driver'      => $this->driver,
            'dsn'         => $this->dsn,
            'prefix'      => $this->prefix,
            'default_ttl' => $this->defaultTtl,
        ];
    }
}
