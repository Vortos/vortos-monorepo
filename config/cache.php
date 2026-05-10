<?php

use Vortos\Cache\DependencyInjection\VortosCacheConfig;

return static function (VortosCacheConfig $config): void {
    // Cache driver and connection defaults are env-driven by the cache module.
    // php vortos setup writes VORTOS_CACHE_DRIVER and VORTOS_CACHE_DSN to .env.
};
