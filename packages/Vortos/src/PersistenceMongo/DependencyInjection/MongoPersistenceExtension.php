<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection;

use MongoDB\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\PersistenceMongo\Connection\MongoClientFactory;

/**
 * Wires MongoDB-specific services.
 *
 * Reads the read DSN and database name from parameters set by PersistenceExtension.
 *
 * ## What this extension registers
 *
 *   MongoDB\Client                             — shared client, built via MongoClientFactory::fromDsn()
 *   vortos.persistence.mongo.database_name     — parameter holding the database name
 *
 * ## What this extension does NOT register
 *
 * User read repositories (subclasses of MongoReadRepository) are NOT registered here.
 * Users register their own read repositories in config/services.php:
 *
 *   $services->set(UserReadRepository::class)
 *       ->arg('$client', service(MongoDB\Client::class))
 *       ->arg('$databaseName', '%vortos.persistence.mongo.database_name%')
 *       ->tag('vortos.read_repository');
 *
 * The 'vortos.read_repository' tag is used by SetupPersistenceCommand
 * to discover all read repositories and ensure their indexes exist.
 *
 * ## MongoDB\Client is not lazy
 *
 * Unlike DBAL, MongoDB\Client connects immediately on construction.
 * If MongoDB is unreachable at container boot time, the application fails.
 * Use health checks in your deployment pipeline to verify MongoDB is ready
 * before starting the application.
 */
final class MongoPersistenceExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_persistence_mongo';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $dsn = $container->getParameter('vortos.persistence.read_dsn');
        $database = $container->getParameter('vortos.persistence.read_database');

        $container->register(Client::class, Client::class)
            ->setFactory([MongoClientFactory::class, 'fromDsn'])
            ->setArguments([$dsn])
            ->setShared(true)
            ->setPublic(true);

        $container->setParameter('vortos.persistence.mongo.database_name', $database);
    }
}
