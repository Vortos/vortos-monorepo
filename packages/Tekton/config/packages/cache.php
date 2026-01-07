<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services();

    $services->set('cache.app', FilesystemAdapter::class)
        ->args([
            'app',                            
            0,                               
            '%kernel.project_dir%/var/cache'  
        ]);

    // ===== Optional: Redis Cache =====

    // Uncomment to use Redis for caching
    // $services->set('redis.connection', RedisAdapter::class)
    //     ->factory([RedisAdapter::class, 'createConnection'])
    //     ->args(['redis://localhost:6379']);
    //
    // $services->set('cache.app', RedisAdapter::class)
    //     ->args([
    //         service('redis.connection'),
    //         'app',
    //         0
    //     ]);
};
