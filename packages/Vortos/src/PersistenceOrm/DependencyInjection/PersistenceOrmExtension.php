<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\PersistenceOrm\Command\OrmDiffCommand;
use Vortos\PersistenceOrm\Command\OrmSchemaCommand;
use Vortos\PersistenceOrm\Factory\EntityManagerFactory;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;

/**
 * Wires Doctrine ORM services.
 *
 * Requires PersistenceExtension (order 60) to be loaded first — this package
 * has order 65 to guarantee vortos.persistence.write_dsn is set before compilation.
 *
 * ## Services registered
 *
 *   EntityManager::class          — shared, lazy Doctrine EntityManager
 *   EntityManagerInterface::class — alias
 *   Connection::class             — DBAL connection extracted from EntityManager
 *                                   (shared with OutboxWriter for atomic writes)
 *   OrmUnitOfWork::class          — transaction boundary via EM::wrapInTransaction()
 *   UnitOfWorkInterface::class    — alias (overrides DBAL's alias when only ORM is active)
 *   OrmSchemaCommand::class       — vortos:orm:schema console command
 *   OrmDiffCommand::class         — vortos:orm:diff  generate migration from entity diff
 *
 * ## Coexistence with DbalPersistenceExtension
 *
 * Do not include both packages if they would register conflicting Connection aliases.
 * Each aggregate family should use one persistence style — ORM or DBAL, not both.
 */
final class PersistenceOrmExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_persistence_orm';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir  = $container->getParameter('kernel.project_dir');
        $entityPaths = [$projectDir . '/src'];
        $devMode     = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

        // %vortos.persistence.write_dsn% is resolved at compile time, not here —
        // safe to reference even though PersistenceExtension loaded before us.
        $container->register(EntityManager::class, EntityManager::class)
            ->setFactory([EntityManagerFactory::class, 'fromDsn'])
            ->setArguments(['%vortos.persistence.write_dsn%', $entityPaths, $devMode])
            ->setShared(true)
            ->setPublic(true)
            ->setLazy(true);

        $container->setAlias(EntityManagerInterface::class, EntityManager::class)
            ->setPublic(true);

        // Expose the connection held by EntityManager so OutboxWriter and
        // TransactionalMiddleware share the exact same DBAL Connection instance —
        // required for atomic aggregate + outbox writes in a single transaction.
        $container->register(Connection::class, Connection::class)
            ->setFactory([new Reference(EntityManager::class), 'getConnection'])
            ->setShared(true)
            ->setPublic(true);

        $container->register(OrmUnitOfWork::class, OrmUnitOfWork::class)
            ->setArgument('$em', new Reference(EntityManager::class))
            ->setPublic(false);

        $container->setAlias(UnitOfWorkInterface::class, OrmUnitOfWork::class)
            ->setPublic(false);

        $container->register(OrmSchemaCommand::class, OrmSchemaCommand::class)
            ->setArgument('$em', new Reference(EntityManagerInterface::class))
            ->setPublic(false)
            ->addTag('console.command');

        $container->register(OrmDiffCommand::class, OrmDiffCommand::class)
            ->setArgument('$em', new Reference(EntityManagerInterface::class))
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$generator', new Reference(MigrationClassGenerator::class))
            ->setPublic(false)
            ->addTag('console.command');
    }
}
