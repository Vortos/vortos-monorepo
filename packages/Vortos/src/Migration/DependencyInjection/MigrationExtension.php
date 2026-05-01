<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Migration\Command\MigrateBaselineCommand;
use Vortos\Migration\Command\MigrateCommand;
use Vortos\Migration\Command\MigrateFreshCommand;
use Vortos\Migration\Command\MigrateMakeCommand;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Command\MigrateRollbackCommand;
use Vortos\Migration\Command\MigrateStatusCommand;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Foundation\Module\ModulePathResolver;
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
 *
 * ## Services registered
 *
 *   DependencyFactoryProvider — lazily builds Doctrine\Migrations\DependencyFactory
 *                               reusing the PersistenceDbal DBAL Connection
 *   ModuleStubScanner         — scans `packages/Vortos/src/{*}/Resources/migrations/{*}.sql`
 *   MigrationClassGenerator   — converts SQL content to a Doctrine migration PHP class
 *
 * ## Configuration
 *
 * Doctrine Migrations config is read from {project_root}/migrations.php.
 * Migration classes live in {project_root}/migrations/ under namespace App\Migrations.
 * The tracking table is vortos_migrations (not Doctrine's default).
 *
 * ## Dependency on PersistenceDbal
 *
 * DependencyFactoryProvider requires Connection::class which is registered by
 * DbalPersistenceExtension (order 70). MigrationExtension loads at order 75.
 * MigrateFreshCommand also injects Connection directly for DROP TABLE operations.
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

        $container->register(ModuleStubScanner::class, ModuleStubScanner::class)
            ->setArgument('$resolver', new Reference(ModulePathResolver::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationClassGenerator::class, MigrationClassGenerator::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrateCommand::class, MigrateCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateStatusCommand::class, MigrateStatusCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$scanner', new Reference(ModuleStubScanner::class))
            ->setArgument('$projectDir', $projectDir)
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
    }
}
