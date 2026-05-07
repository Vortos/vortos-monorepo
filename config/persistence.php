<?php

use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

return static function (VortosPersistenceConfig $config): void {
    // Persistence DSNs are env-driven by the persistence module.
    // php vortos setup writes VORTOS_WRITE_DB_DSN and read database settings to .env.local.
};
