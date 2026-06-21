<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Vortos\PersistenceOrm\EntityManager\ResettableEntityManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\PersistenceOrm\Command\OrmDiffCommand;
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
 *   EntityManager::class            — shared, lazy raw Doctrine EntityManager (internal)
 *   ResettableEntityManager::class  — public wrapper; owns ResetInterface lifecycle
 *   EntityManagerInterface::class   — alias → ResettableEntityManager
 *   Connection::class               — DBAL connection extracted from EntityManager
 *                                     (shared with OutboxWriter for atomic writes)
 *   OrmUnitOfWork::class            — transaction boundary via DBAL beginTransaction/commit/rollBack
 *   UnitOfWorkInterface::class      — alias (overrides DBAL's alias when only ORM is active)
 *   OrmDiffCommand::class           — vortos:orm:diff  generate migration from entity diff
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
        $devMode     = $container->getParameter('kernel.env') === 'dev';
        $dsn         = (string) $container->getParameter('vortos.persistence.write_dsn');

        // Tenant isolation wiring is applied by TenantOrmWiringPass
        // (PersistenceOrmPackage::build): the TenantStampListener, the Doctrine
        // TenantFilter, the scoped-entity map and the OrmTenantSessionBinder are all
        // patched onto these definitions there. A has(TenantContext::class) check
        // inside load() runs against the per-extension merge container, where
        // TenantContext (owned by vortos-tenant) is never present. The EntityManager
        // is registered below with tenant-neutral defaults that the pass overwrites.
        $emFilters        = [];
        $emEnabledFilters = [];
        $emEventListeners = [];

        // Arg 3 ($metadataCache) is intentionally null here. OrmMetadataCachePass
        // runs after all extensions are merged and patches this argument if
        // TaggedCacheInterface is available — avoiding the false-negative from
        // MergeExtensionConfigurationPass isolating extensions in temp containers.
        // Positional args 0-3 stay as-is: OrmMetadataCachePass patches index 3
        // and N1DetectionCompilerPass manages index 4 ($middlewares). The tenant
        // params are passed by name so they never collide with those indices.
        $container->register(EntityManager::class, EntityManager::class)
            ->setFactory([EntityManagerFactory::class, 'fromDsn'])
            ->setArguments([$dsn, $entityPaths, $devMode, null])
            ->setArgument('$filters', $emFilters)
            ->setArgument('$enabledFilters', $emEnabledFilters)
            ->setArgument('$eventListeners', $emEventListeners)
            ->setArgument('$scopedEntities', [])
            ->setShared(true)
            ->setPublic(false)
            ->setLazy(true);

        $container->register(ResettableEntityManager::class, ResettableEntityManager::class)
            ->setArgument('$inner', new Reference(EntityManager::class))
            ->setShared(true)
            ->setPublic(true);

        $container->setAlias(EntityManagerInterface::class, ResettableEntityManager::class)
            ->setPublic(true);

        // Expose the connection held by EntityManager so OutboxWriter and
        // TransactionalMiddleware share the exact same DBAL Connection instance —
        // required for atomic aggregate + outbox writes in a single transaction.
        $container->register(Connection::class, Connection::class)
            ->setFactory([new Reference(EntityManager::class), 'getConnection'])
            ->setShared(true)
            ->setPublic(true);

        $container->register(OrmUnitOfWork::class, OrmUnitOfWork::class)
            ->setArgument('$em', new Reference(EntityManagerInterface::class))
            ->setPublic(false);

        // The OrmTenantSessionBinder (app.current_tenant GUC for the ORM filter +
        // RLS) and the OrmUnitOfWork $tenantBinder argument are wired by
        // TenantOrmWiringPass when the tenant package is present.

        $container->setAlias(UnitOfWorkInterface::class, OrmUnitOfWork::class)
            ->setPublic(false);

        $container->register(OrmDiffCommand::class, OrmDiffCommand::class)
            ->setArgument('$em', new Reference(EntityManagerInterface::class))
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$generator', new Reference(MigrationClassGenerator::class))
            ->setPublic(false)
            ->addTag('console.command');
    }
}
