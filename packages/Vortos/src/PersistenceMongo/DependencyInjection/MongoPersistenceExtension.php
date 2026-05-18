<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection;

use MongoDB\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceMongo\Connection\MongoClientFactory;
use Vortos\PersistenceMongo\Health\MongoHealthCheck;

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
 * ## Read repository auto-wiring
 *
 * Subclasses of MongoReadRepository registered in services.php are detected
 * at compile time by MongoReadRepositoryAutowirePass. The pass injects
 * MongoDB\Client and the database name parameter automatically, and adds the
 * 'vortos.read_repository' tag so SetupPersistenceCommand and tracing passes
 * discover the repository without any manual configuration:
 *
 *   // services.php — this is all that is required:
 *   $services->set(UserReadRepository::class);
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
        $dsn = (string) $container->getParameter('vortos.persistence.read_dsn');
        $database = (string) $container->getParameter('vortos.persistence.read_database');

        $container->register(Client::class, Client::class)
            ->setFactory([MongoClientFactory::class, 'fromDsn'])
            ->setArguments([$dsn])
            ->setShared(true)
            ->setPublic(true);

        $container->setParameter('vortos.persistence.mongo.database_name', $database);
        $container->setParameter('vortos.persistence.mongo.cursor_secret', $_ENV['VORTOS_CURSOR_SECRET'] ?? '');

        $container->register(MongoHealthCheck::class, MongoHealthCheck::class)
            ->setArgument('$client', new Reference(Client::class))
            ->setPublic(false);
    }
}
