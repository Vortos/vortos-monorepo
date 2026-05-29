<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection;

use MongoDB\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceMongo\Command\MongoSyncCommand;
use Vortos\PersistenceMongo\Connection\MongoClientFactory;
use Vortos\PersistenceMongo\Health\MongoHealthCheck;
use Vortos\PersistenceMongo\Schema\MongoIndexAttributeScanner;

/**
 * Wires MongoDB-specific services.
 *
 * ## What this extension registers
 *
 *   MongoDB\Client                             — shared client, built via MongoClientFactory::fromDsn()
 *   vortos.persistence.mongo.database_name     — parameter holding the database name
 *   MongoIndexAttributeScanner                 — populated with repository class names at compile time
 *   MongoSyncCommand                           — vortos:mongo:sync console command
 *
 * ## Read repository auto-wiring
 *
 * Repository classes carrying #[MongoCollection] are detected at compile time
 * by MongoReadRepositoryAutowirePass. The pass creates a named MongoStore service per
 * repository, injects it as the $store constructor argument, tags it with
 * 'vortos.read_repository', and registers the class with MongoIndexAttributeScanner
 * so vortos:mongo:sync can discover #[MongoIndex] attributes.
 *
 *   // services.php — this is all that is required:
 *   $services->set(UserReadRepository::class);
 *
 * ## MongoDB index management
 *
 * Declare indexes via #[MongoIndex] attributes on the repository class:
 *
 *   #[MongoCollection('users')]
 *   #[MongoIndex(key: ['email' => 1], unique: true)]
 *   final class UserReadRepository implements UserReadRepositoryInterface { ... }
 *
 * Apply on every deploy:
 *   php bin/console vortos:mongo:sync
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
        $dsn      = (string) $container->getParameter('vortos.persistence.read_dsn');
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

        $container->register(MongoIndexAttributeScanner::class, MongoIndexAttributeScanner::class)
            ->setShared(true)
            ->setPublic(true);

        $container->register(MongoSyncCommand::class, MongoSyncCommand::class)
            ->setArgument('$client', new Reference(Client::class))
            ->setArgument('$databaseName', '%vortos.persistence.mongo.database_name%')
            ->setArgument('$scanner', new Reference(MongoIndexAttributeScanner::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
