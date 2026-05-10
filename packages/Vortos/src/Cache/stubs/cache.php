<?php

declare(strict_types=1);

use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\DependencyInjection\VortosCacheConfig;

// The cache driver is chosen by VORTOS_CACHE_DRIVER in .env:
//   VORTOS_CACHE_DRIVER=redis        → RedisAdapter (persistent, multi-process safe)
//   VORTOS_CACHE_DRIVER=in-memory    → InMemoryAdapter (process-local, zero config)
//
// The DSN and key prefix default to VORTOS_CACHE_DSN and VORTOS_CACHE_PREFIX.
// Override those ENV vars or call the fluent methods below for fine-grained control.
//
// For per-environment overrides create config/{env}/cache.php.

return static function (VortosCacheConfig $config): void {
    // Key prefix applied to every cache key.
    //
    // Always include APP_ENV and a project identifier to avoid key collisions
    // when multiple environments share one Redis instance.
    //
    // The default already reads VORTOS_CACHE_PREFIX (set by vortos:setup),
    // so you only need this if you want an explicit value regardless of ENV.
    //
    // $config->prefix(($_ENV['APP_ENV'] ?? 'dev') . '_myapp_');

    // Default TTL in seconds for keys stored without an explicit TTL.
    // 0 = no expiry — avoid this with Redis (unbounded memory growth).
    $config->defaultTtl(3600);

    // Override the driver programmatically (rarely needed — use ENV instead).
    //
    // $config->driver(RedisAdapter::class);   // Redis (prod)
    // $config->driver(InMemoryAdapter::class); // In-process (dev/test)

    // Override the Redis DSN.
    // Format: redis://[:password@]host[:port][/database]
    // Ignored when driver is InMemoryAdapter.
    //
    // $config->dsn($_ENV['VORTOS_CACHE_DSN'] ?? 'redis://127.0.0.1:6379');
};
