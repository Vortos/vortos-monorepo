<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Reference as SfReference;
use Vortos\Migration\Command\MigrateAdoptCommand;
use Vortos\Migration\Command\MigrateCommand;
use Vortos\Migration\Command\MigrateFreshCommand;
use Vortos\Migration\Command\MigrateMakeCommand;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Command\MigrateRollbackCommand;
use Vortos\Migration\Command\MigrateStatusCommand;
use Vortos\Migration\Command\MigrateUnadoptCommand;
use Vortos\Migration\Command\MigrateVerifyCommand;
use Vortos\Migration\Console\MigrateAnalyzeCommand;
use Vortos\Migration\Console\MigrateDownVerifyCommand;
use Vortos\Migration\Driver\PgNative\PgNativeSafetyAnalyzer;
use Vortos\Migration\Driver\PgNative\PgTargetStatsReader;
use Vortos\Migration\Driver\PgNative\Rule\BlockingAlterRule;
use Vortos\Migration\Driver\PgNative\Rule\ConcurrentInTransactionRule;
use Vortos\Migration\Driver\PgNative\Rule\FullTableRewriteRule;
use Vortos\Migration\Driver\PgNative\Rule\LockTimeoutMissingRule;
use Vortos\Migration\Driver\PgNative\Rule\NonConcurrentIndexRule;
use Vortos\Migration\Driver\PgNative\Rule\NonIdempotentConcurrentRule;
use Vortos\Migration\Driver\PgNative\Rule\NotNullNoDefaultRule;
use Vortos\Migration\Driver\PgNative\Rule\PhaseMismatchRule;
use Vortos\Migration\Driver\PgNative\Rule\PhaseUndeclaredRule;
use Vortos\Migration\Driver\PgNative\Rule\VolatileDefaultRule;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Safety\MigrationArtifactFactory;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\PendingMigrationVersionProvider;
use Vortos\Migration\Safety\PendingMigrationVersionProviderInterface;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerRegistry;
use Vortos\Migration\Safety\Rule\SafetyRuleSet;
use Vortos\Migration\Safety\SchemaDriftAuditor;
use Vortos\Migration\Safety\SchemaDriftAuditorInterface;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\MigrationDriftFormatter;
use Vortos\Migration\Service\BackfillSafetyAnalyzer;
use Vortos\Migration\Service\MigrationLock;
use Vortos\Migration\Service\MigrationLockSafetyEnforcer;
use Vortos\Migration\Service\MigrationPhaseHeuristic;
use Vortos\Migration\Service\MigrationPlanAnalyzer;
use Vortos\Migration\Service\MigrationPreflight;
use Vortos\Migration\Service\TransactionAwareMigrationRunner;
use Vortos\Migration\Service\ModuleFlagGateMetadataReader;
use Vortos\Migration\Service\ModuleMigrationPhaseReader;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Migration\Service\MigrationRawInspectorInterface;
use Vortos\Migration\Service\MigrationSchemaInspector;
use Vortos\Migration\Service\MigrationSchemaInspectorInterface;
use Vortos\Migration\Service\MigrationSqlExtractor;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;
use Vortos\Migration\Service\MigrationSqlParser;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;
use Vortos\Migration\Service\UserMigrationOwnershipExtractor;

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
 *   vortos:migrate:adopt      — mark existing schema as executed without SQL (--module-only, --allow-unverified)
 *   vortos:migrate:unadopt    — remove a migration tracking record without touching schema
 *   vortos:migrate:verify     — CI check: all executed framework migrations match live schema (exit 0 = clean)
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

        // Fluent configuration (config/migration.php → config/{env}/migration.php).
        // Becomes the single source of truth for the settings below; the env-var defaults
        // baked into VortosMigrationConfig preserve prior behavior when no file is present.
        $migrationConfig = $this->loadConfig($projectDir, $env);
        $safetyConfig    = $migrationConfig->getSafety();
        $allOrNothing    = $migrationConfig->getAllOrNothing();

        $container->setParameter('vortos.migration.all_or_nothing', $allOrNothing);
        $container->setParameter('vortos.migration.lock_timeout_ms', $migrationConfig->getLockTimeoutMs());
        $container->setParameter('vortos.migration.statement_timeout_ms', $migrationConfig->getStatementTimeoutMs());
        $container->setParameter('vortos.migration.safety.hot_table.row_threshold', $safetyConfig->getHotTableRowThreshold());
        $container->setParameter('vortos.migration.safety.hot_table.bytes_threshold', $safetyConfig->getHotTableBytesThreshold());

        // Transactionality-aware runner shared by migrate / migrate:fresh / migrate:rollback.
        // Driver-agnostic: keys off Doctrine's per-migration isTransactional() flag only.
        $container->register(TransactionAwareMigrationRunner::class, TransactionAwareMigrationRunner::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(DependencyFactoryProvider::class, DependencyFactoryProvider::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        // Publish the interface so cross-package consumers (e.g. vortos-release's
        // DoctrineAppliedMigrationSetReader) can inject it by contract, not concrete.
        $container->setAlias(DependencyFactoryProviderInterface::class, DependencyFactoryProvider::class)
            ->setPublic(false);

        // ModulePathResolver is registered by vortos-foundation (its owning package); reference
        // it directly rather than re-registering a fallback here.
        $container->register(ModuleStubScanner::class, ModuleStubScanner::class)
            ->setArgument('$resolver', new Reference(ModulePathResolver::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);

        $container->register(ModuleSchemaProviderScanner::class, ModuleSchemaProviderScanner::class)
            ->setArgument('$resolver', new Reference(ModulePathResolver::class))
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$frameworkTablePrefix', $container->hasParameter('vortos.db.framework_table_prefix')
                ? $container->getParameter('vortos.db.framework_table_prefix')
                : 'vortos_')
            ->setShared(true)
            ->setPublic(false);

        // R8-1: single source of truth for "would publish emit anything?" — used by the publish
        // command and by the deploy preflight gate (UnpublishedStubCheck). Public so the deploy
        // check can reference it across packages.
        $container->register(\Vortos\Migration\Service\UnpublishedStubDetector::class, \Vortos\Migration\Service\UnpublishedStubDetector::class)
            ->setArgument('$scanner', new Reference(ModuleStubScanner::class))
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$schemaScanner', new Reference(ModuleSchemaProviderScanner::class))
            ->setShared(true)
            ->setPublic(true);

        $container->register(ModuleMigrationRegistry::class, ModuleMigrationRegistry::class)
            ->setArgument('$schemaScanner', new Reference(ModuleSchemaProviderScanner::class))
            ->setArgument('$projectDir', $projectDir)
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ModuleMigrationRegistryInterface::class, ModuleMigrationRegistry::class)
            ->setPublic(false);

        $container->register(MigrationSchemaInspector::class, MigrationSchemaInspector::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationSchemaInspectorInterface::class, MigrationSchemaInspector::class)
            ->setPublic(false);
        $container->setAlias(MigrationRawInspectorInterface::class, MigrationSchemaInspector::class)
            ->setPublic(false);

        $container->register(MigrationDriftDetector::class, MigrationDriftDetector::class)
            ->setArgument('$inspector', new Reference(MigrationSchemaInspectorInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationDriftDetectorInterface::class, MigrationDriftDetector::class)
            ->setPublic(false);

        $container->register(MigrationDriftFormatter::class, MigrationDriftFormatter::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(UserMigrationOwnershipExtractor::class, UserMigrationOwnershipExtractor::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationPreflight::class, MigrationPreflight::class)
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationSqlParser::class, MigrationSqlParser::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(MigrationSqlExtractor::class, MigrationSqlExtractor::class)
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationSqlExtractorInterface::class, MigrationSqlExtractor::class)
            ->setPublic(false);

        $container->register(MigrationPlanAnalyzer::class, MigrationPlanAnalyzer::class)
            ->setArgument('$inspector', new Reference(MigrationRawInspectorInterface::class))
            ->setArgument('$extractor', new Reference(MigrationSqlExtractorInterface::class))
            ->setArgument('$parser', new Reference(MigrationSqlParser::class))
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

        $frameworkTablePrefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(MigrateCommand::class, MigrateCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$planAnalyzer', new Reference(MigrationPlanAnalyzer::class))
            ->setArgument('$lock', new Reference(MigrationLock::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$frameworkTablePrefix', $frameworkTablePrefix)
            ->setArgument('$runner', new Reference(TransactionAwareMigrationRunner::class))
            ->setArgument('$allOrNothing', $allOrNothing)
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
            ->setArgument('$stubDetector', new Reference(\Vortos\Migration\Service\UnpublishedStubDetector::class))
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
            ->setArgument('$runner', new Reference(TransactionAwareMigrationRunner::class))
            ->setArgument('$allOrNothing', $allOrNothing)
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
            ->setArgument('$frameworkTablePrefix', $frameworkTablePrefix)
            ->setArgument('$runner', new Reference(TransactionAwareMigrationRunner::class))
            ->setArgument('$allOrNothing', $allOrNothing)
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateAdoptCommand::class, MigrateAdoptCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setArgument('$driftFormatter', new Reference(MigrationDriftFormatter::class))
            ->setArgument('$ownershipExtractor', new Reference(UserMigrationOwnershipExtractor::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateUnadoptCommand::class, MigrateUnadoptCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateVerifyCommand::class, MigrateVerifyCommand::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistry::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetector::class))
            ->setPublic(true)
            ->addTag('console.command');

        // ── Block 8: Phase vocabulary + safety ──

        $container->register(ModuleMigrationPhaseReader::class, ModuleMigrationPhaseReader::class)
            ->setArgument('$registry', new Reference(ModuleMigrationRegistryInterface::class))
            ->setArgument('$artifactFactory', new Reference(MigrationArtifactFactoryInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationPhaseReaderInterface::class, ModuleMigrationPhaseReader::class)
            ->setPublic(false);

        $container->register(ModuleFlagGateMetadataReader::class, ModuleFlagGateMetadataReader::class)
            ->setArgument('$registry', new Reference(ModuleMigrationRegistryInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(FlagGateMetadataReaderInterface::class, ModuleFlagGateMetadataReader::class)
            ->setPublic(false);

        $container->register(MigrationPhaseHeuristic::class, MigrationPhaseHeuristic::class)
            ->setArgument('$extractor', new Reference(MigrationSqlExtractorInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(BackfillSafetyAnalyzer::class, BackfillSafetyAnalyzer::class)
            ->setArgument('$extractor', new Reference(MigrationSqlExtractorInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $lockTimeoutMs = $container->hasParameter('vortos.migration.lock_timeout_ms')
            ? (int) $container->getParameter('vortos.migration.lock_timeout_ms')
            : 3000;
        $statementTimeoutMs = $container->hasParameter('vortos.migration.statement_timeout_ms')
            ? (int) $container->getParameter('vortos.migration.statement_timeout_ms')
            : 0;

        $container->register(MigrationLockSafetyEnforcer::class, MigrationLockSafetyEnforcer::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$lockTimeoutMs', $lockTimeoutMs)
            ->setArgument('$statementTimeoutMs', $statementTimeoutMs)
            ->setShared(true)
            ->setPublic(false);

        // ── Block 21: Safety Analyzer + Drift/Down-verify ──

        $hotTableRowThreshold = $container->hasParameter('vortos.migration.safety.hot_table.row_threshold')
            ? (int) $container->getParameter('vortos.migration.safety.hot_table.row_threshold')
            : 100_000;
        $hotTableBytesThreshold = $container->hasParameter('vortos.migration.safety.hot_table.bytes_threshold')
            ? (int) $container->getParameter('vortos.migration.safety.hot_table.bytes_threshold')
            : 67_108_864;

        $ruleSetDef = $container->register(SafetyRuleSet::class, SafetyRuleSet::class)
            ->setArgument('$severityOverrides', $safetyConfig->getSeverityOverrides())
            ->setShared(true)
            ->setPublic(false);

        $ruleClasses = [
            NonConcurrentIndexRule::class => [],
            NonIdempotentConcurrentRule::class => [],
            ConcurrentInTransactionRule::class => [],
            VolatileDefaultRule::class => ['$rowThreshold' => $hotTableRowThreshold, '$bytesThreshold' => $hotTableBytesThreshold],
            NotNullNoDefaultRule::class => [],
            BlockingAlterRule::class => ['$rowThreshold' => $hotTableRowThreshold, '$bytesThreshold' => $hotTableBytesThreshold],
            LockTimeoutMissingRule::class => ['$enforcerLockTimeoutMs' => $lockTimeoutMs],
            FullTableRewriteRule::class => [],
            PhaseMismatchRule::class => [],
            PhaseUndeclaredRule::class => [],
        ];

        foreach ($ruleClasses as $ruleClass => $args) {
            $ruleDef = $container->register($ruleClass, $ruleClass)->setShared(true)->setPublic(false);
            foreach ($args as $argName => $argValue) {
                $ruleDef->setArgument($argName, $argValue);
            }
            $ruleSetDef->addMethodCall('add', [new Reference($ruleClass)]);
        }

        // Fail fast on a severity override that names an unknown rule id (runs after every add()).
        $ruleSetDef->addMethodCall('validateOverrides');

        $container->register(PgNativeSafetyAnalyzer::class, PgNativeSafetyAnalyzer::class)
            ->setArgument('$ruleSet', new Reference(SafetyRuleSet::class))
            ->addTag('vortos.migration.safety_analyzer')
            ->setShared(true)
            ->setPublic(false);

        $container->register(PgTargetStatsReader::class, PgTargetStatsReader::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos.migration.safety_analyzer_locator', MigrationSafetyAnalyzerRegistry::class)
            ->setArgument(0, [])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationSafetyAnalyzerRegistry::class, 'vortos.migration.safety_analyzer_locator')
            ->setPublic(false);

        $container->setAlias(MigrationSafetyAnalyzerInterface::class, PgNativeSafetyAnalyzer::class)
            ->setPublic(false);

        $container->register(MigrationArtifactFactory::class, MigrationArtifactFactory::class)
            ->setArgument('$extractor', new Reference(MigrationSqlExtractorInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(MigrationArtifactFactoryInterface::class, MigrationArtifactFactory::class)
            ->setPublic(false);

        $container->register(SchemaDriftAuditor::class, SchemaDriftAuditor::class)
            ->setArgument('$moduleRegistry', new Reference(ModuleMigrationRegistryInterface::class))
            ->setArgument('$driftDetector', new Reference(MigrationDriftDetectorInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(SchemaDriftAuditorInterface::class, SchemaDriftAuditor::class);

        $container->register(PendingMigrationVersionProvider::class, PendingMigrationVersionProvider::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(PendingMigrationVersionProviderInterface::class, PendingMigrationVersionProvider::class);

        $container->register(MigrateAnalyzeCommand::class, MigrateAnalyzeCommand::class)
            ->setArgument('$analyzer', new Reference(MigrationSafetyAnalyzerInterface::class))
            ->setArgument('$artifactFactory', new Reference(MigrationArtifactFactoryInterface::class))
            ->setArgument('$versionProvider', new Reference(PendingMigrationVersionProviderInterface::class))
            ->setArgument('$statsReader', new Reference(PgTargetStatsReader::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MigrateDownVerifyCommand::class, MigrateDownVerifyCommand::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProvider::class))
            ->setArgument('$artifactFactory', new Reference(MigrationArtifactFactoryInterface::class))
            ->setArgument('$analyzer', new Reference(MigrationSafetyAnalyzerInterface::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->registerForAutoconfiguration(MigrationSafetyAnalyzerInterface::class)
            ->addTag('vortos.migration.safety_analyzer');
    }

    /**
     * Loads config/migration.php then config/{env}/migration.php (env overrides base),
     * mirroring how every other vortos module resolves its fluent config.
     */
    private function loadConfig(string $projectDir, string $env): VortosMigrationConfig
    {
        $config = new VortosMigrationConfig();

        $base = $projectDir . '/config/migration.php';
        if (is_file($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/migration.php';
        if (is_file($envFile)) {
            (require $envFile)($config);
        }

        return $config;
    }
}
