<?php

use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\DependencyInjection\VortosCacheConfig;

return static function (VortosCacheConfig $config): void {
    if (($_ENV['VORTOS_CACHE_DRIVER'] ?? 'redis') === 'in-memory') {
        $config->driver(InMemoryAdapter::class);
        return;
    }

    $config
        ->dsn(sprintf('redis://%s:%s', $_ENV['REDIS_HOST'], $_ENV['REDIS_PORT']))
        ->prefix($_ENV['VORTOS_CACHE_PREFIX'] ?? ($_ENV['APP_ENV'] . '_' . ($_ENV['APP_NAME'] ?? 'app') . '_'))
        ->defaultTtl(3600);
};
