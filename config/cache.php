<?php

use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\DependencyInjection\VortosCacheConfig;

return static function (VortosCacheConfig $config): void {
    $config
        ->dsn(sprintf('redis://%s:%s', $_ENV['REDIS_HOST'], $_ENV['REDIS_PORT']))
        ->prefix($_ENV['APP_ENV'] . '_squaura_')
        ->defaultTtl(3600);

    // $config->driver(InMemoryAdapter::class);
};
