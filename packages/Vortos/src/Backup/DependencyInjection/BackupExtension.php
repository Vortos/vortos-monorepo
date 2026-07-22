<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Catalog\CatalogManifestWriter;
use Vortos\Backup\Catalog\DbalBackupCatalogReadModel;
use Vortos\Backup\Catalog\DbalBackupCatalogRepository;
use Vortos\Backup\Console\BackupDrillCommand;
use Vortos\Backup\Console\BackupDrRunbookCommand;
use Vortos\Backup\Console\BackupListCommand;
use Vortos\Backup\Console\BackupReplicateCommand;
use Vortos\Backup\Console\BackupRestoreCommand;
use Vortos\Backup\Console\BackupRetentionCommand;
use Vortos\Backup\Console\BackupRunCommand;
use Vortos\Backup\Console\BackupScheduleCommand;
use Vortos\Backup\Console\BackupVerifyCommand;
use Vortos\Backup\Console\BackupWalArchiveCommand;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupEventSinksPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupStoresPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupTargetsPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectInvariantChecksPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectRestoreTargetsPass;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\DR\DrRunbookGenerator;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Drill\Check\ReferentialIntegrityInvariant;
use Vortos\Backup\Drill\Check\RowCountInvariant;
use Vortos\Backup\Drill\Check\SmokeQueryInvariant;
use Vortos\Backup\Drill\DbalDrillReportStore;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Driver\Mongo\MongoBackupTarget;
use Vortos\Backup\Driver\Mongo\MongoProcessFactory;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Driver\Postgres\PostgresBackupTarget;
use Vortos\Backup\Driver\Postgres\PostgresProcessFactory;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Event\CompositeBackupEventSink;
use Vortos\Backup\Event\LoggingBackupEventSink;
use Vortos\Backup\Immutability\ImmutabilityVerifier;
use Vortos\Backup\Immutability\ObjectLockProbe;
use Vortos\Backup\Pitr\PitrPreflight;
use Vortos\Backup\Pitr\PostgresWalArchiver;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetInterface;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Replication\SecondaryReplicator;
use Vortos\Backup\Restore\Driver\Mongo\MongoRestoreProcessFactory;
use Vortos\Backup\Restore\Driver\Mongo\MongoRestoreTarget;
use Vortos\Backup\Restore\Driver\Postgres\PostgresRestoreProcessFactory;
use Vortos\Backup\Restore\Driver\Postgres\PostgresRestoreTarget;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\CronFragmentGenerator;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\StreamTransformInterface;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Service\SystemClock;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\Secrets\Key\KeyProviderInterface;

final class BackupExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_backup';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : '%kernel.project_dir%';

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $catalogTable = $prefix . 'backup_catalog';
        $drillTable = $prefix . 'backup_drill_report';

        $storeKey = (string) ($_ENV['VORTOS_BACKUP_STORE'] ?? 'object-store');
        $keyPrefix = (string) ($_ENV['VORTOS_BACKUP_KEY_PREFIX'] ?? 'backups');
        $lockDir = (string) ($_ENV['VORTOS_BACKUP_LOCK_DIR'] ?? ($projectDir . '/var/backup-locks'));
        $mongoUri = (string) ($_ENV['VORTOS_BACKUP_MONGO_URI'] ?? '');
        $keyProviderName = (string) ($_ENV['VORTOS_BACKUP_KEY_PROVIDER'] ?? '');
        $drillDsn = (string) ($_ENV['VORTOS_BACKUP_DRILL_DSN'] ?? '');
        // Container-mode drill config. DOCKER_HOST must be the least-privilege socket-proxy endpoint
        // (tcp://docker-socket-proxy:2375) — DockerEngineContainerRuntime refuses a raw socket.
        $drillDockerHost = (string) ($_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST'] ?? '');
        // Pin to the production server version: a drill against a different major tests a migration
        // you are not planning to perform.
        $drillImage = (string) ($_ENV['VORTOS_BACKUP_DRILL_IMAGE'] ?? 'postgres:18-alpine');
        // The shared network the drill container must join so the backup node can reach it by name.
        $drillNetwork = (string) ($_ENV['VORTOS_BACKUP_DRILL_NETWORK'] ?? '');
        $drillAllowSharedHost = filter_var($_ENV['VORTOS_BACKUP_DRILL_ALLOW_SHARED_HOST'] ?? false, FILTER_VALIDATE_BOOL);
        $primaryDsn = (string) ($_ENV['VORTOS_WRITE_DB_DSN'] ?? '');
        $secondaryStoreName = (string) ($_ENV['VORTOS_BACKUP_SECONDARY_STORE'] ?? '');
        $objectLockDays = (int) ($_ENV['VORTOS_BACKUP_OBJECT_LOCK_DAYS'] ?? 0);
        $objectLockMode = (string) ($_ENV['VORTOS_BACKUP_OBJECT_LOCK_MODE'] ?? 'compliance');
        $rpoSeconds = (int) ($_ENV['VORTOS_BACKUP_RPO_SECONDS'] ?? 300);
        $rtoSeconds = (int) ($_ENV['VORTOS_BACKUP_RTO_SECONDS'] ?? 1800);
        $defaultEngine = (string) ($_ENV['VORTOS_BACKUP_ENGINE'] ?? '');

        // ── Driver locators + registries ──
        $container->register(CollectBackupTargetsPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);
        $container->register(BackupTargetRegistry::class, BackupTargetRegistry::class)
            ->setArgument('$drivers', new Reference(CollectBackupTargetsPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(CollectBackupStoresPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);
        $container->register(BackupStoreRegistry::class, BackupStoreRegistry::class)
            ->setArgument('$drivers', new Reference(CollectBackupStoresPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(CollectRestoreTargetsPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);
        $container->register(RestoreTargetRegistry::class, RestoreTargetRegistry::class)
            ->setArgument('$drivers', new Reference(CollectRestoreTargetsPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(BackupTargetInterface::class)->addTag(CollectBackupTargetsPass::TAG);
        $container->registerForAutoconfiguration(BackupStoreInterface::class)->addTag(CollectBackupStoresPass::TAG);
        $container->registerForAutoconfiguration(BackupEventSinkInterface::class)->addTag(CollectBackupEventSinksPass::TAG);
        $container->registerForAutoconfiguration(RestoreTargetInterface::class)->addTag(CollectRestoreTargetsPass::TAG);
        $container->registerForAutoconfiguration(InvariantCheck::class)->addTag(CollectInvariantChecksPass::TAG);

        // ── Default backup target drivers ──
        $container->register(PostgresProcessFactory::class, PostgresProcessFactory::class)
            ->setArgument('$primary', new Reference(Connection::class))
            ->setArgument('$replica', null)
            ->setPublic(false);
        $container->register(PostgresBackupTarget::class, PostgresBackupTarget::class)
            ->setArgument('$processes', new Reference(PostgresProcessFactory::class))
            ->setAutoconfigured(true)
            ->addTag(CollectBackupTargetsPass::TAG)
            ->setPublic(false);

        $container->register(MongoProcessFactory::class, MongoProcessFactory::class)
            ->setArgument('$uri', $mongoUri)
            ->setPublic(false);
        $container->register(MongoBackupTarget::class, MongoBackupTarget::class)
            ->setArgument('$processes', new Reference(MongoProcessFactory::class))
            ->setAutoconfigured(true)
            ->addTag(CollectBackupTargetsPass::TAG)
            ->setPublic(false);

        $container->register(ObjectStoreBackupStore::class, ObjectStoreBackupStore::class)
            ->setArgument('$objectStore', new Reference(ImmediateObjectStoreInterface::class))
            ->setAutoconfigured(true)
            ->addTag(CollectBackupStoresPass::TAG)
            ->setPublic(false);

        // ── Restore target drivers ──
        $container->register(PostgresRestoreProcessFactory::class, PostgresRestoreProcessFactory::class)
            ->setPublic(false);
        $container->register(PostgresRestoreTarget::class, PostgresRestoreTarget::class)
            ->setArgument('$processes', new Reference(PostgresRestoreProcessFactory::class))
            ->addTag(CollectRestoreTargetsPass::TAG)
            ->setPublic(false);

        $container->register(MongoRestoreProcessFactory::class, MongoRestoreProcessFactory::class)
            ->setPublic(false);
        $container->register(MongoRestoreTarget::class, MongoRestoreTarget::class)
            ->setArgument('$processes', new Reference(MongoRestoreProcessFactory::class))
            ->addTag(CollectRestoreTargetsPass::TAG)
            ->setPublic(false);

        // ── Catalog (append-only DBAL) ──
        $container->register(DbalBackupCatalogRepository::class, DbalBackupCatalogRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $catalogTable)
            ->setPublic(false);
        $container->setAlias(BackupCatalogRepositoryInterface::class, DbalBackupCatalogRepository::class)->setPublic(false);

        $container->register(DbalBackupCatalogReadModel::class, DbalBackupCatalogReadModel::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $catalogTable)
            ->setPublic(false);
        $container->setAlias(BackupCatalogReadModelInterface::class, DbalBackupCatalogReadModel::class)->setPublic(false);

        // ── Event seam (Block-17-ready) ──
        $container->register(LoggingBackupEventSink::class, LoggingBackupEventSink::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->addTag(CollectBackupEventSinksPass::TAG)
            ->setPublic(false);
        $container->register(CompositeBackupEventSink::class, CompositeBackupEventSink::class)
            ->setArgument('$sinks', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setPublic(false);
        $container->setAlias(BackupEventSinkInterface::class, CompositeBackupEventSink::class)->setPublic(false);

        // ── Engine resolution (fail-closed default) + toolchain doctor (STAGE-F-1) ──
        $container->register(\Vortos\Backup\Domain\EngineResolver::class, \Vortos\Backup\Domain\EngineResolver::class)
            ->setArgument('$configuredDefault', $defaultEngine !== '' ? $defaultEngine : null)
            ->setPublic(false);
        $container->register(\Vortos\Backup\Doctor\BackupToolchainInspector::class, \Vortos\Backup\Doctor\BackupToolchainInspector::class)
            ->setPublic(false);

        // ── Core services ──
        $container->register(SystemClock::class, SystemClock::class)->setPublic(false);
        $container->register(EnvelopeStreamCipher::class, EnvelopeStreamCipher::class)->setPublic(false);
        $container->register(IdentityStreamTransform::class, IdentityStreamTransform::class)->setPublic(false);
        $container->setAlias(StreamTransformInterface::class, IdentityStreamTransform::class)->setPublic(false);

        $container->register(IntegrityVerifier::class, IntegrityVerifier::class)->setPublic(false);
        $container->register(BackupLock::class, BackupLock::class)
            ->setArgument('$lockDir', $lockDir)
            ->setPublic(false);

        // ── R8-6: config/backup.php loader — source of the retention policy, the typed lifecycle
        //    schedules, and (via the worker) the containerized runtime. Deferred to runtime so the
        //    real project dir/env are available and a malformed config fails loudly at boot. ──
        $kernelEnv = $container->hasParameter('kernel.env') ? (string) $container->getParameter('kernel.env') : 'prod';
        $container->register(\Vortos\Backup\Config\BackupConfigLoader::class, \Vortos\Backup\Config\BackupConfigLoader::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$env', $kernelEnv)
            ->setPublic(false);

        // Retention policy: config/backup.php when present (with cadence-derived hourly), else default.
        $container->register(RetentionPolicy::class, RetentionPolicy::class)
            ->setFactory([new Reference(\Vortos\Backup\Config\BackupConfigLoader::class), 'retentionPolicy'])
            ->setPublic(false);

        // ── Object Lock Policy (if configured) ──
        $lockPolicy = null;
        if ($objectLockDays > 0) {
            $container->register(ObjectLockPolicy::class, ObjectLockPolicy::class)
                ->setArgument('$mode', $objectLockMode)
                ->setArgument('$retentionDays', $objectLockDays)
                ->setPublic(false);
            $lockPolicy = new Reference(ObjectLockPolicy::class);
        }

        $container->register(RetentionEnforcer::class, RetentionEnforcer::class)
            ->setArgument('$readModel', new Reference(BackupCatalogReadModelInterface::class))
            ->setArgument('$repository', new Reference(BackupCatalogRepositoryInterface::class))
            ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$lockPolicy', $lockPolicy)
            ->setPublic(false);

        $container->register(BackupRunner::class, BackupRunner::class)
            ->setArgument('$targets', new Reference(BackupTargetRegistry::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$catalog', new Reference(BackupCatalogRepositoryInterface::class))
            ->setArgument('$verifier', new Reference(IntegrityVerifier::class))
            ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
            ->setArgument('$transform', new Reference(StreamTransformInterface::class))
            ->setArgument('$lock', new Reference(BackupLock::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$storeKey', $storeKey)
            ->setArgument('$keyPrefix', $keyPrefix)
            ->setPublic(false);

        // ── Restore ──
        $container->register(RestoreCoordinator::class, RestoreCoordinator::class)
            ->setArgument('$targets', new Reference(RestoreTargetRegistry::class))
            ->setArgument('$cipher', new Reference(EnvelopeStreamCipher::class))
            ->setArgument('$keyProvider', new Reference(KeyProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // ── Drill ──
        $container->register(DbalDrillReportStore::class, DbalDrillReportStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $drillTable)
            ->setPublic(false);
        $container->setAlias(DrillReportStoreInterface::class, DbalDrillReportStore::class)->setPublic(false);

        $container->register(RowCountInvariant::class, RowCountInvariant::class)
            ->addTag(CollectInvariantChecksPass::TAG)
            ->setPublic(false);
        $container->register(ReferentialIntegrityInvariant::class, ReferentialIntegrityInvariant::class)
            ->addTag(CollectInvariantChecksPass::TAG)
            ->setPublic(false);
        $container->register(SmokeQueryInvariant::class, SmokeQueryInvariant::class)
            ->addTag(CollectInvariantChecksPass::TAG)
            ->setPublic(false);

        // Restore drills need an ephemeral-database provisioner, which only binds when one of the two
        // provisioning modes is configured. Registering DrillRunner unconditionally left its
        // $provisioner port unbound and broke every console/worker boot on a stock backup install.
        // Register the whole drill stack only when a mode is selected; the command below stays visible
        // and fails loudly when it is not.
        //
        // Two modes, and the container one is what you want in production:
        //  • CONTAINER (VORTOS_BACKUP_DRILL_DOCKER_HOST) — restore into a disposable, clean Postgres
        //    container. Proves the backup reconstitutes on a *fresh* server, which is the only version
        //    of the question a disaster actually asks, and keeps the restore off the primary entirely.
        //  • SAME-SERVER (VORTOS_BACKUP_DRILL_DSN) — `CREATE DATABASE` on a server you nominate.
        //    Cheap and dependency-free, appropriate for local dev; in production it tends to end up
        //    pointed at the primary, so it now guards on topology rather than on DSN spelling.
        // Container mode wins when both are set.
        if ($drillDockerHost !== '') {
            $container->register(\Vortos\Backup\Drill\Container\DockerEngineContainerRuntime::class)
                ->setArgument('$endpoint', $drillDockerHost)
                ->setPublic(false);
            $container->setAlias(
                \Vortos\Backup\Drill\Container\ContainerRuntimeInterface::class,
                \Vortos\Backup\Drill\Container\DockerEngineContainerRuntime::class,
            )->setPublic(false);

            $container->register(\Vortos\Backup\Drill\Driver\Postgres\ContainerizedDatabaseProvisioner::class)
                ->setArgument('$runtime', new Reference(\Vortos\Backup\Drill\Container\ContainerRuntimeInterface::class))
                ->setArgument('$image', $drillImage)
                ->setArgument('$network', $drillNetwork !== '' ? $drillNetwork : null)
                ->setPublic(false);
            $container->setAlias(DrillEnvironmentProvisionerInterface::class, \Vortos\Backup\Drill\Driver\Postgres\ContainerizedDatabaseProvisioner::class);
        } elseif ($drillDsn !== '') {
            $container->register(\Vortos\Backup\Drill\Driver\Postgres\EphemeralDatabaseProvisioner::class)
                ->setArgument('$drillDsn', $drillDsn)
                // Hand the guard the real write-DB connection so it can compare topology instead of
                // pattern-matching the DSN string (which passed a production primary in practice).
                ->setArgument('$primaryDsn', $primaryDsn !== '' ? $primaryDsn : null)
                ->setArgument('$allowSharedHost', $drillAllowSharedHost)
                ->setPublic(false);
            $container->setAlias(DrillEnvironmentProvisionerInterface::class, \Vortos\Backup\Drill\Driver\Postgres\EphemeralDatabaseProvisioner::class);
        }

        if ($drillDockerHost !== '' || $drillDsn !== '') {

            $container->register(DrillRunner::class, DrillRunner::class)
                ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
                ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
                ->setArgument('$restoreCoordinator', new Reference(RestoreCoordinator::class))
                ->setArgument('$provisioner', new Reference(DrillEnvironmentProvisionerInterface::class))
                ->setArgument('$reportStore', new Reference(DrillReportStoreInterface::class))
                ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
                ->setArgument('$clock', new Reference(SystemClock::class))
                ->setArgument('$invariantChecks', [])
                ->setArgument('$storeKey', $storeKey)
                ->setArgument('$keyProvider', new Reference(KeyProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(false);
        }

        // ── Replication (3-2-1) ──
        $secondaryRef = ($secondaryStoreName !== '' && $container->has($secondaryStoreName))
            ? new Reference($secondaryStoreName)
            : null;
        $container->register(SecondaryReplicator::class, SecondaryReplicator::class)
            ->setArgument('$secondaryStore', $secondaryRef)
            ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setPublic(false);

        // ── Immutability ──
        $container->register(ImmutabilityVerifier::class, ImmutabilityVerifier::class)->setPublic(false);
        $container->register(ObjectLockProbe::class, ObjectLockProbe::class)->setPublic(false);

        // ── DR ──
        $container->register(RecoveryObjectives::class, RecoveryObjectives::class)
            ->setArgument('$rpoSeconds', $rpoSeconds)
            ->setArgument('$rtoSeconds', $rtoSeconds)
            ->setPublic(false);

        $container->register(DrRunbookGenerator::class, DrRunbookGenerator::class)
            ->setArgument('$objectives', new Reference(RecoveryObjectives::class))
            ->setArgument('$lockPolicy', $lockPolicy)
            ->setArgument('$reportStore', new Reference(DrillReportStoreInterface::class))
            ->setArgument('$primaryStore', $storeKey)
            ->setArgument('$secondaryStore', $secondaryStoreName !== '' ? $secondaryStoreName : null)
            ->setArgument('$keyProviderName', $keyProviderName !== '' ? $keyProviderName : 'none')
            ->setPublic(false);

        // ── Catalog manifest (D9 self-recovery) ──
        $container->register(CatalogManifestWriter::class, CatalogManifestWriter::class)
            ->setArgument('$readModel', new Reference(BackupCatalogReadModelInterface::class))
            ->setPublic(false);

        // ── PITR ──
        $container->register(PostgresWalArchiver::class, PostgresWalArchiver::class)
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$catalog', new Reference(BackupCatalogRepositoryInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$storeKey', $storeKey)
            ->setArgument('$keyPrefix', $keyPrefix)
            ->setPublic(false);
        $container->register(PitrPreflight::class, PitrPreflight::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setPublic(false);

        // ── Schedule ──
        // R8-6: the registry is now populated from config/backup.php (typed backup/retention/drill
        // schedules), via the loader factory. Empty when no config file is present.
        $container->register(BackupScheduleRegistry::class, BackupScheduleRegistry::class)
            ->setFactory([new Reference(\Vortos\Backup\Config\BackupConfigLoader::class), 'scheduleRegistry'])
            ->setPublic(false);
        $container->register(CronFragmentGenerator::class, CronFragmentGenerator::class)->setPublic(false);

        // ── R8-6: containerized backup worker (A8) — the framework-owned runtime that fires the whole
        //    declared lifecycle on its crons, replacing host cron on a lean deploy. ──
        //
        // DEPLOY REQUIREMENT: this path must be on storage that outlives the container. It is the
        // worker's watermark; losing it is a cold start. Since alpha-258 a cold start runs the
        // schedule once rather than going silent (see BackupWorker::isDue()), so an ephemeral path
        // degrades to "one extra backup per container recreate" instead of the silent 15-day outage it
        // caused in production on 2026-07-07 — but it should still be a mounted volume.
        $container->register(\Vortos\Backup\Runtime\ScheduleStateStoreInterface::class, \Vortos\Backup\Runtime\FileScheduleStateStore::class)
            ->setArgument('$path', $projectDir . '/var/backup-schedule-state.json')
            ->setPublic(false);

        // ── Freshness: the catalog-derived dead-man check (the counterpart to `backup.failed`) ──
        $container->register(\Vortos\Backup\Schedule\CadenceInterval::class, \Vortos\Backup\Schedule\CadenceInterval::class)
            ->setArgument('$evaluator', new Reference(\Vortos\Backup\Runtime\CronDueEvaluator::class))
            ->setPublic(false);

        $container->register(\Vortos\Backup\Health\BackupFreshnessInspector::class, \Vortos\Backup\Health\BackupFreshnessInspector::class)
            ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
            ->setArgument('$schedules', new Reference(BackupScheduleRegistry::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$cadence', new Reference(\Vortos\Backup\Schedule\CadenceInterval::class))
            ->setPublic(false);

        $container->register(\Vortos\Backup\Console\BackupFreshnessCommand::class, \Vortos\Backup\Console\BackupFreshnessCommand::class)
            ->setArgument('$inspector', new Reference(\Vortos\Backup\Health\BackupFreshnessInspector::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
            ->addTag('console.command')->setPublic(false);

        // Only when vortos-health is installed — vortos-backup must not hard-depend on it. The probe
        // is MONITORING kind, so it reaches /health/monitor and the off-host monitor tick without ever
        // being able to fail a readiness gate or roll back a deploy.
        if (interface_exists(\Vortos\Health\Probe\HealthProbeInterface::class)) {
            $container->register(\Vortos\Backup\Health\BackupFreshnessProbe::class, \Vortos\Backup\Health\BackupFreshnessProbe::class)
                ->setArgument('$inspector', new Reference(\Vortos\Backup\Health\BackupFreshnessInspector::class))
                ->addTag(\Vortos\Health\DependencyInjection\Compiler\CollectHealthProbesPass::TAG)
                ->setPublic(false);
        }

        $container->register(\Vortos\Backup\Runtime\CronDueEvaluator::class, \Vortos\Backup\Runtime\CronDueEvaluator::class)
            ->setPublic(false);

        $lifecycleRunner = $container->register(\Vortos\Backup\Runtime\BackupLifecycleRunner::class, \Vortos\Backup\Runtime\BackupLifecycleRunner::class)
            ->setArgument('$backupRunner', new Reference(BackupRunner::class))
            ->setArgument('$retentionEnforcer', new Reference(RetentionEnforcer::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$retentionPolicy', new Reference(RetentionPolicy::class))
            ->setArgument('$storeKey', $storeKey)
            ->setArgument('$drillRunner', new Reference(DrillRunner::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);
        $container->setAlias(\Vortos\Backup\Runtime\BackupLifecycleRunnerInterface::class, \Vortos\Backup\Runtime\BackupLifecycleRunner::class)->setPublic(false);

        $container->register(\Vortos\Backup\Runtime\BackupWorker::class, \Vortos\Backup\Runtime\BackupWorker::class)
            ->setArgument('$schedules', new Reference(BackupScheduleRegistry::class))
            ->setArgument('$runner', new Reference(\Vortos\Backup\Runtime\BackupLifecycleRunnerInterface::class))
            ->setArgument('$state', new Reference(\Vortos\Backup\Runtime\ScheduleStateStoreInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$events', new Reference(BackupEventSinkInterface::class))
            ->setArgument('$evaluator', new Reference(\Vortos\Backup\Runtime\CronDueEvaluator::class))
            ->setPublic(false);

        $container->register(\Vortos\Backup\Console\BackupWorkerCommand::class, \Vortos\Backup\Console\BackupWorkerCommand::class)
            ->setArgument('$worker', new Reference(\Vortos\Backup\Runtime\BackupWorker::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->addTag('console.command')->setPublic(false);

        // R8-6 (A8): emit the backup worker as a supervisord program so vortos-docker manages it like
        // any other worker — but ONLY when the app opts in (VORTOS_BACKUP_WORKER_SUPERVISED=true, set
        // on the backup-role image), so it is never forced onto the lean app/worker colors that lack
        // the DB client. Guarded by class_exists so vortos-backup does not hard-depend on vortos-docker.
        if (self::envFlag($_ENV['VORTOS_BACKUP_WORKER_SUPERVISED'] ?? null)
            && class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)
        ) {
            $container->register('vortos.backup.worker_process', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArgument('$name', 'backup-worker')
                ->setArgument('$command', 'php bin/console vortos:backup:worker')
                ->setArgument('$description', 'Vortos containerized backup lifecycle (backup/retention/drill)')
                ->setArgument('$stopwaitsecs', 300)
                ->setArgument('$drainDeadline', 25)
                ->addTag(\Vortos\Docker\DependencyInjection\DockerExtension::WORKER_TAG)
                ->setPublic(false);
        }

        // ── Console ──
        $container->register(BackupRunCommand::class, BackupRunCommand::class)
            ->setArgument('$runner', new Reference(BackupRunner::class))
            ->setArgument('$engineResolver', new Reference(\Vortos\Backup\Domain\EngineResolver::class))
            ->addTag('console.command')->setPublic(false);
        $container->register(\Vortos\Backup\Console\BackupDoctorCommand::class, \Vortos\Backup\Console\BackupDoctorCommand::class)
            ->setArgument('$engineResolver', new Reference(\Vortos\Backup\Domain\EngineResolver::class))
            ->setArgument('$inspector', new Reference(\Vortos\Backup\Doctor\BackupToolchainInspector::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$storeKey', $storeKey)
            ->setArgument('$connection', new Reference(Connection::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupListCommand::class, BackupListCommand::class)
            ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupRetentionCommand::class, BackupRetentionCommand::class)
            ->setArgument('$enforcer', new Reference(RetentionEnforcer::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$defaultPolicy', new Reference(RetentionPolicy::class))
            ->setArgument('$storeKey', $storeKey)
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupVerifyCommand::class, BackupVerifyCommand::class)
            ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$verifier', new Reference(IntegrityVerifier::class))
            ->setArgument('$storeKey', $storeKey)
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupWalArchiveCommand::class, BackupWalArchiveCommand::class)
            ->setArgument('$archiver', new Reference(PostgresWalArchiver::class))
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupScheduleCommand::class, BackupScheduleCommand::class)
            ->setArgument('$schedules', new Reference(BackupScheduleRegistry::class))
            ->setArgument('$generator', new Reference(CronFragmentGenerator::class))
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupDrillCommand::class, BackupDrillCommand::class)
            ->setArgument('$runner', new Reference(DrillRunner::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupReplicateCommand::class, BackupReplicateCommand::class)
            ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$replicator', new Reference(SecondaryReplicator::class))
            ->setArgument('$storeKey', $storeKey)
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupRestoreCommand::class, BackupRestoreCommand::class)
            ->setArgument('$catalog', new Reference(BackupCatalogReadModelInterface::class))
            ->setArgument('$stores', new Reference(BackupStoreRegistry::class))
            ->setArgument('$coordinator', new Reference(RestoreCoordinator::class))
            ->setArgument('$storeKey', $storeKey)
            ->addTag('console.command')->setPublic(false);
        $container->register(BackupDrRunbookCommand::class, BackupDrRunbookCommand::class)
            ->setArgument('$generator', new Reference(DrRunbookGenerator::class))
            ->addTag('console.command')->setPublic(false);

        // Containerized PITR (WAL-shipping) recipe generator (P3-1).
        $container->register(\Vortos\Backup\Pitr\ContainerizedPitrRecipe::class, \Vortos\Backup\Pitr\ContainerizedPitrRecipe::class)
            ->setPublic(false);
        $container->register(\Vortos\Backup\Console\BackupPitrRecipeCommand::class, \Vortos\Backup\Console\BackupPitrRecipeCommand::class)
            ->setArgument('$recipe', new Reference(\Vortos\Backup\Pitr\ContainerizedPitrRecipe::class))
            ->setArgument('$projectDir', $projectDir)
            ->addTag('console.command')->setPublic(false);
    }

    private static function envFlag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL);
    }
}
