<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Migration\Command\MigrateBaselineCommand;
use Vortos\Migration\Command\MigrateAdoptCommand;
use Vortos\Migration\Command\MigrateCommand;
use Vortos\Migration\Command\MigrateFreshCommand;
use Vortos\Migration\Command\MigrateMakeCommand;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Command\MigrateRollbackCommand;
use Vortos\Migration\Command\MigrateStatusCommand;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationDriftFormatter;
use Vortos\Migration\Service\MigrationLock;
use Vortos\Migration\Service\MigrationPreflight;
use Vortos\Migration\Service\MigrationSchemaInspector;
use Vortos\Migration\Service\MigrationSchemaInspectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;

/**
 * Wires all migration services and console commands.
 *
 * ## Commands registered
 *
 *   vortos:migrate            — run all pending migrations
 *   vortos:migrate:status     — show migration state + unpublished stub warnings
 *   vortos:migrate:make       — generate an empty migration class
 *   vortos:migrate:rollback   — undo last N migrations
 *   vortos:migrate:publish    — convert module SQL stubs → Doctrine migration classes
 *   vortos:migrate:fresh      — drop all tables and re-run (non-production only)
 *   vortos:migrate:baseline   — mark all available migrations as already executed
 *   vortos:migrate:adopt      — mark verified existing module schema as executed
 *
 * ## Services registered
 *
 *   DependencyFactoryProvider — lazily builds Doctrine\Migrations\DependencyFactory
 *                               reusing the PersistenceDbal DBAL Connection
 *   ModuleStubScanner         — scans `packages/Vortos/src/{*}/Resources/migrations/{*}.sql`
 *   ModuleSchemaProviderScanner — scans `packages/Vortos/src/{*}/Resources/migrations/{*}.php`
 *   MigrationClassGenerator   — converts SQL content to a Doctrine migration PHP class
 *
 * ## Configuration
 *
 * Doctrine Migrations config is read from {project_root}/migrations.php.
 * Migration classes live in {project_root}/migrations/ under namespace App\Migrations.
 * The tracking table is vortos_migrations (not Doctrine's default).
 *
 * ## Connection dependency
 *
 * DependencyFactoryProvider requires Connection::class, which is registered by
 * either DbalPersistenceExtension (order 70) or PersistenceOrmExtension (order 65,
 * which extracts the connection from the EntityManager). MigrationExtension loads
 * at order 75, after both. MigrateFreshCommand also injects Connection directly
 * for DROP TABLE operations. Do not include this module without one of those two.
 */
final class MigrationExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_migration';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $container->register(DependencyFactoryProvider::class, DependencyFactoryProvider::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        if (!$container->has(ModulePathResolver::class)) {
            $container->register(ModulePathResolver::class, ModulePathResolver::class)
                ->setArgument('$projectDir', $projectDir)
                ->setShared(true)
                ->setPublic(false);
        }

        $container->register(ModuleStubScanner::class, ModuleStubScanner::class)
            ->setArgument('$resolver', new Reference(ModulePathResolver::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        $container->register(ModuleSchemaProviderScanner::class, ModuleSchemaProviderScanner::class)
            ->setArgument('$resolver', new Reference(ModulePathResolver::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        $container->register(ModuleMigrationRegistry::class, ModuleMigrationRegistry::class)
            ->setArgument('$schemaScanner', new Reference(ModuleSchemaProviderScanner::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationSchemaInspector::class, MigrationSchemaInspector::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationSchemaInspectorInterface::class, MigrationSchemaInspector::class)
            ->setPublic(false);

        $container->register(MigrationDriftDetector::class, MigrationDriftDetector::class)
            ->setArgument('$inspector', new Reference(MigrationSchemaInspectorInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationDriftFormatter::class, MigrationDriftFormatter::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationPreflight::class, MigrationPreflight::class)
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationLock::class, MigrationLock::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationClassGenerator::class, MigrationClassGenerator::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrateCommand::class, MigrateCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$preflight', new Reference(MigrationPreflight::class))
            ->setArgument('$driftFormatter', new Reference(MigrationDriftFormatter::class))
            ->setArgument('$lock', new Reference(MigrationLock::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateStatusCommand::class, MigrateStatusCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$scanner', new Reference(ModuleStubScanner::class))
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setArgument('$driftFormatter', new Reference(MigrationDriftFormatter::class))
            ->setArgument('$schemaScanner', new Reference(ModuleSchemaProviderScanner::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateMakeCommand::class, MigrateMakeCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$generator', new Reference(MigrationClassGenerator::class))
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateRollbackCommand::class, MigrateRollbackCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigratePublishCommand::class, MigratePublishCommand::class)
            ->setArgument('$scanner', new Reference(ModuleStubScanner::class))
            ->setArgument('$generator', new Reference(MigrationClassGenerator::class))
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$schemaScanner', new Reference(ModuleSchemaProviderScanner::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateFreshCommand::class, MigrateFreshCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$env', $env)
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateBaselineCommand::class, MigrateBaselineCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateAdoptCommand::class, MigrateAdoptCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setArgument('$driftFormatter', new Reference(MigrationDriftFormatter::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
