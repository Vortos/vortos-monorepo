<?php

use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

return static function (VortosPersistenceConfig $config): void {
    $config
        ->writeDsn($_ENV['DATABASE_URL'] ?? sprintf(
            'pgsql://%s:%s@%s:5432/%s',
            $_ENV['POSTGRES_USER'],
            $_ENV['POSTGRES_PASSWORD'],
            $_ENV['POSTGRES_HOST'],
            $_ENV['POSTGRES_DB_NAME'],
        ))
        ->readDsn(sprintf(
            'mongodb://%s:%s@%s:%s',
            $_ENV['MONGO_INITDB_ROOT_USERNAME'],
            $_ENV['MONGO_INITDB_ROOT_PASSWORD'],
            $_ENV['MONGO_HOST'],
            $_ENV['MONGO_PORT'],
        ))
        ->readDatabase($_ENV['MONGO_DB_NAME']);
};
