<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Action\AuditActionProviderInterface;
use Vortos\Audit\Action\AuditActionRegistry;
use Vortos\Audit\AuditTrail;
use Vortos\Audit\AuditTrailInterface;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\DependencyInjection\Compiler\AuditActionProviderPass;
use Vortos\Audit\Enum\FailureMode;
use Vortos\Audit\Ingestion\AsyncAuditRecorder;
use Vortos\Audit\Ingestion\AuditIngestionHandler;
use Vortos\Audit\Ingestion\AuditIngestionProcessor;
use Vortos\Audit\Ingestion\Idempotency\IdempotencyGuardInterface;
use Vortos\Audit\Ingestion\Idempotency\InMemoryIdempotencyGuard;
use Vortos\Audit\Ingestion\Idempotency\RedisIdempotencyGuard;
use Vortos\Audit\Clock\SystemClock;
use Vortos\Audit\Console\AuditDoctorCommand;
use Vortos\Audit\Console\AuditPgInstallCommand;
use Vortos\Audit\Console\AuditRetentionCommand;
use Vortos\Audit\Enum\AuditSearchDriver;
use Vortos\Audit\Http\AuditTenantRlsMiddleware;
use Vortos\Audit\SavedView\AuditSavedViewStoreInterface;
use Vortos\Audit\SavedView\Dbal\DbalAuditSavedViewStore;
use Vortos\Audit\Search\AuditSearchIndexInterface;
use Vortos\Audit\Search\LikeSearchIndex;
use Vortos\Audit\Search\PostgresFtsSearchIndex;
use Vortos\Audit\Storage\Dbal\Postgres\AuditTenantGuc;
use Vortos\Audit\Storage\Dbal\Postgres\PostgresAuditExtrasInstaller;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Admin\AuditPermissionCatalog;
use Vortos\Audit\Doctor\AuditDoctor;
use Vortos\Audit\Console\AuditExportGcCommand;
use Vortos\Audit\Export\AuditExportEnqueuer;
use Vortos\Audit\Export\AuditExportJobStoreInterface;
use Vortos\Audit\Export\Dbal\DbalAuditExportJobStore;
use Vortos\Audit\Observability\AuditMetricDefinitions;
use Vortos\Audit\Observability\AuditMetrics;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Query\Dbal\DbalAuditQueryReader;
use Vortos\Audit\Recorder\NullAuditRecorder;
use Vortos\Audit\Retention\AuditArchiveWriterInterface;
use Vortos\Audit\Retention\AuditCheckpointStoreInterface;
use Vortos\Audit\Retention\AuditRetentionPolicy;
use Vortos\Audit\Retention\AuditRetentionSweeper;
use Vortos\Audit\Retention\Dbal\DbalAuditCheckpointStore;
use Vortos\Audit\Retention\ObjectStore\ObjectStoreArchiveWriter;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\Audit\Storage\Dbal\DbalAuditStore;
use Vortos\Audit\Storage\Dbal\Lock\ChainLockStrategyInterface;
use Vortos\Audit\Storage\Dbal\Lock\PgAdvisoryChainLock;
use Vortos\Audit\Storage\Dbal\Lock\RowChainLock;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\StandaloneEventBusInterface;

/**
 * Wires the audit domain core (P1).
 *
 * Storage (P2), async ingestion (P3), retention (P4), query/export (P5) attach their
 * own recorder/reader services and re-alias AuditRecorderInterface as they are added;
 * until then the Null recorder keeps AuditTrailInterface callable (and loud) everywhere.
 */
final class AuditExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_audit';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config  = $this->loadConfig($container);
        $strict  = $config['strict'];
        $hmacKey = $config['hmac_key'];

        // Any AuditActionProviderInterface impl is auto-tagged; the compiler pass folds
        // them all into the registry.
        $container->registerForAutoconfiguration(AuditActionProviderInterface::class)
            ->addTag(AuditActionProviderInterface::TAG);

        $container->register(AuditActionRegistry::class, AuditActionRegistry::class)
            ->setArgument('$providers', []) // filled by AuditActionProviderPass
            ->setPublic(false);

        // Tamper-evidence primitives (pure, no infrastructure).
        $container->register(AuditHashChain::class, AuditHashChain::class)->setPublic(false);
        $container->register(AuditChainVerifier::class, AuditChainVerifier::class)
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setPublic(false);

        $this->registerStorage($container, $hmacKey, $config);
        $this->registerIngestion($container, $config);
        $this->registerExport($container, $config, $hmacKey);
        $this->registerRetention($container, $config);
        $this->registerAdmin($container, $hmacKey);
        $this->registerObservability($container, $config, $hmacKey);

        // Default sink: Null recorder (logs a warning) unless the DBAL store already
        // claimed the alias above.
        if (!$container->hasDefinition(NullAuditRecorder::class)) {
            $container->register(NullAuditRecorder::class, NullAuditRecorder::class)
                ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(false);
        }
        if (!$container->hasAlias(AuditRecorderInterface::class)
            && !$container->hasDefinition(AuditRecorderInterface::class)) {
            $container->setAlias(AuditRecorderInterface::class, NullAuditRecorder::class);
        }

        // App-facing facade.
        $container->register(AuditTrail::class, AuditTrail::class)
            ->setArgument('$recorder', new Reference(AuditRecorderInterface::class))
            ->setArgument('$registry', new Reference(AuditActionRegistry::class))
            ->setArgument('$strict', $strict)
            ->setPublic(true);

        $container->setAlias(AuditTrailInterface::class, AuditTrail::class)->setPublic(true);
    }

    /**
     * Wire the DBAL-backed append-only store as the recorder + reader when Doctrine DBAL
     * is installed. Until then the Null recorder stands in. P3 will front this with the
     * Kafka-decoupled recorder.
     */
    private function registerStorage(ContainerBuilder $container, string $hmacKey, array $config): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $isPostgres = $this->isPostgres($container);

        // Per-chain append lock: Postgres advisory lock (no extra table, no contention) when
        // the store runs on Postgres; the portable SELECT ... FOR UPDATE row lock otherwise.
        // Auto-detected from the write DSN, Postgres-first when the platform is unknown.
        if ($isPostgres) {
            $container->register(PgAdvisoryChainLock::class, PgAdvisoryChainLock::class)->setPublic(false);
            $container->setAlias(ChainLockStrategyInterface::class, PgAdvisoryChainLock::class);
        } else {
            $container->register(RowChainLock::class, RowChainLock::class)
                ->setArgument('$headsTable', $prefix . 'audit_chain_heads')
                ->setPublic(false);
            $container->setAlias(ChainLockStrategyInterface::class, RowChainLock::class);
        }

        // Free-text search index: Postgres FTS when configured + on Postgres, else portable LIKE.
        $ftsSelected = ($config['search_driver'] ?? 'postgres_fts') === AuditSearchDriver::PostgresFts->value && $isPostgres;
        if ($ftsSelected) {
            $container->register(PostgresFtsSearchIndex::class, PostgresFtsSearchIndex::class)->setPublic(false);
            $container->setAlias(AuditSearchIndexInterface::class, PostgresFtsSearchIndex::class);
        } else {
            $container->register(LikeSearchIndex::class, LikeSearchIndex::class)->setPublic(false);
            $container->setAlias(AuditSearchIndexInterface::class, LikeSearchIndex::class);
        }

        $container->register(DbalAuditStore::class, DbalAuditStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setArgument('$hmacKey', $hmacKey)
            ->setArgument('$table', $prefix . 'audit_events')
            ->setArgument('$lock', new Reference(ChainLockStrategyInterface::class))
            ->setPublic(false);

        // The DBAL store becomes the recorder + reader of record.
        $container->setAlias(AuditRecorderInterface::class, DbalAuditStore::class);
        $container->setAlias(AuditReaderInterface::class, DbalAuditStore::class);

        // Read side (P5 + F2): keyset query with prefix/search/facets + signed export.
        $container->register(DbalAuditQueryReader::class, DbalAuditQueryReader::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'audit_events')
            ->setArgument('$search', new Reference(AuditSearchIndexInterface::class))
            ->setPublic(false);
        $container->setAlias(AuditQueryInterface::class, DbalAuditQueryReader::class);

        $container->register(StoredAuditEventSerializer::class, StoredAuditEventSerializer::class)->setPublic(false);

        // Async export (P6): the durable job store is always available with DBAL present; the
        // object-store-backed exporter, sink, service and consumer handler are wired later by
        // AuditExportObjectStorePass (their object-store alias check must run post-load), and
        // the enqueuer (producer) is wired by registerExport() when messaging is present.
        $container->register(DbalAuditExportJobStore::class, DbalAuditExportJobStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'audit_export_jobs')
            ->setPublic(false);
        $container->setAlias(AuditExportJobStoreInterface::class, DbalAuditExportJobStore::class);

        // Saved views (F2): named, scope-bound console filter sets.
        $container->register(DbalAuditSavedViewStore::class, DbalAuditSavedViewStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'audit_saved_views')
            ->setPublic(true);
        $container->setAlias(AuditSavedViewStoreInterface::class, DbalAuditSavedViewStore::class)->setPublic(true);

        // Postgres-only extras (F2): FTS GIN index + RLS installer/guard + install command.
        if ($isPostgres) {
            $container->register(PostgresAuditExtrasInstaller::class, PostgresAuditExtrasInstaller::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'audit_events')
                ->setPublic(false);

            $container->register(AuditTenantGuc::class, AuditTenantGuc::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setPublic(true);

            // RLS enforcement: a request middleware sets app.current_tenant per request so the
            // tenant-isolation policy actually confines org sessions. Only when RLS is enabled
            // AND vortos-http + vortos-tenant are present (the class's use-imports need them).
            if (($config['row_level_security'] ?? false) === true
                && interface_exists('Vortos\Http\Contract\MiddlewareInterface')
                && class_exists('Vortos\Tenant\TenantContext')) {
                $container->register(AuditTenantRlsMiddleware::class, AuditTenantRlsMiddleware::class)
                    ->setArgument('$guc', new Reference(AuditTenantGuc::class))
                    ->setArgument('$tenantContext', new Reference('Vortos\Tenant\TenantContext'))
                    ->addTag('vortos.http_middleware')
                    ->setPublic(false);
            }
        }

        // The install command exists whenever DBAL is present; it refuses to run off Postgres.
        $container->register(AuditPgInstallCommand::class, AuditPgInstallCommand::class)
            ->setArgument('$installer', $isPostgres ? new Reference(PostgresAuditExtrasInstaller::class) : null)
            ->setArgument('$rlsConfigured', (bool) ($config['row_level_security'] ?? false))
            ->addTag('console.command')
            ->setPublic(false);
    }

    /**
     * Wire the async ingestion path (P3). The consumer side (guard + processor + handler)
     * is registered whenever the message bus and the DBAL store are both present, so a
     * consumer worker can drain the topic. The producer side only takes over the recorder
     * alias when async is explicitly enabled AND the app has declared the 'vortos.audit'
     * consumer — otherwise the synchronous store from registerStorage() stays in force.
     *
     * @param array{async: bool, failure_mode: string, idempotency_ttl_seconds: int, redis_dsn: string, ...} $config
     */
    private function registerIngestion(ContainerBuilder $container, array $config): void
    {
        if (!interface_exists(EventBusInterface::class) || !$container->hasDefinition(DbalAuditStore::class)) {
            return;
        }

        // Idempotency guard: Redis when configured (cross-process), else process-local.
        $redisFactory = 'Vortos\Cache\Adapter\RedisConnectionFactory';
        if ($config['redis_dsn'] !== '' && extension_loaded('redis') && class_exists($redisFactory)) {
            $container->register('vortos_audit.redis', \Redis::class)
                ->setFactory([$redisFactory, 'fromDsn'])
                ->setArgument(0, $config['redis_dsn'])
                ->setPublic(false);

            $container->register(RedisIdempotencyGuard::class, RedisIdempotencyGuard::class)
                ->setArgument('$redis', new Reference('vortos_audit.redis'))
                ->setPublic(false);
            $container->setAlias(IdempotencyGuardInterface::class, RedisIdempotencyGuard::class);
        } else {
            $container->register(InMemoryIdempotencyGuard::class, InMemoryIdempotencyGuard::class)
                ->setPublic(false);
            $container->setAlias(IdempotencyGuardInterface::class, InMemoryIdempotencyGuard::class);
        }

        // Processor uses the CONCRETE store (never the recorder alias) to avoid re-enqueue.
        $container->register(AuditIngestionProcessor::class, AuditIngestionProcessor::class)
            ->setArgument('$store', new Reference(DbalAuditStore::class))
            ->setArgument('$guard', new Reference(IdempotencyGuardInterface::class))
            ->setArgument('$idempotencyTtlSeconds', $config['idempotency_ttl_seconds'])
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$metrics', new Reference(AuditMetrics::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        if (!$config['async']) {
            // Synchronous mode: no producer, and crucially NO event handler — the handler
            // references the 'vortos.audit' consumer, which only a async-enabled app
            // declares. Registering + tagging it here would fail an app's messaging
            // wire-contract check for referencing an undeclared consumer.
            return;
        }

        // Consumer entrypoint — only when async is on (the app has declared 'vortos.audit').
        $container->register(AuditIngestionHandler::class, AuditIngestionHandler::class)
            ->setArgument('$processor', new Reference(AuditIngestionProcessor::class))
            ->addTag('vortos.event_handler')
            ->setPublic(false);

        // Standalone bus: audit is recorded outside business transactions (auth middleware,
        // read paths), where the transactional outbox write would fail. StandaloneEventBus
        // opens its own transaction when none is active, and joins one when present.
        $container->register(AsyncAuditRecorder::class, AsyncAuditRecorder::class)
            ->setArgument('$eventBus', new Reference(StandaloneEventBusInterface::class))
            ->setArgument('$failureMode', FailureMode::tryFrom($config['failure_mode']) ?? FailureMode::Block)
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Producer becomes the recorder of record; the request path now enqueues.
        $container->setAlias(AuditRecorderInterface::class, AsyncAuditRecorder::class);
    }

    /**
     * Wire async export (P6). The job store is already registered in registerStorage(); here
     * we surface the tuning as container parameters (consumed by AuditExportObjectStorePass,
     * which wires the object-store-dependent sink/exporter/service/handler post-load) and, when
     * export can actually dispatch (async on + messaging present), register the enqueuer
     * producer. Like ingestion, the consumer-side handler is only wired when async is on — an
     * app enabling async audit declares BOTH the 'vortos.audit' and 'vortos.audit.export'
     * consumers in its messaging config.
     *
     * @param array<string, mixed> $config
     */
    private function registerExport(ContainerBuilder $container, array $config, string $hmacKey): void
    {
        if (!$container->hasDefinition(DbalAuditExportJobStore::class)) {
            return;
        }

        // Match the ingestion producer's gate exactly: config flag + the bus INTERFACE existing.
        // Never test container->has(StandaloneEventBusInterface) here — extension load order is
        // not guaranteed, so the messaging extension may not have registered it yet at this point
        // (it resolves fine at compile-end). An app that enables async audit ships messaging.
        $exportAsync = (bool) $config['async'] && interface_exists(EventBusInterface::class);

        // Tuning handed to AuditExportObjectStorePass (runs after every extension's load()).
        $container->setParameter('vortos_audit.export_async', $exportAsync);
        $container->setParameter('vortos_audit.export_hmac_key', $hmacKey);
        $container->setParameter('vortos_audit.export_key_prefix', (string) $config['export_key_prefix']);
        $container->setParameter('vortos_audit.export_page_size', (int) $config['export_page_size']);
        $container->setParameter('vortos_audit.export_artifact_retention_days', (int) $config['export_artifact_retention_days']);
        $container->setParameter('vortos_audit.export_download_url_ttl_seconds', (int) $config['export_download_url_ttl_seconds']);

        // Clock is shared with retention; register once, idempotently.
        if (!$container->hasDefinition(SystemClock::class)) {
            $container->register(SystemClock::class, SystemClock::class)->setPublic(false);
        }

        if ($exportAsync) {
            // Producer: persists the Queued job and dispatches the request envelope.
            $container->register(AuditExportEnqueuer::class, AuditExportEnqueuer::class)
                ->setArgument('$jobs', new Reference(AuditExportJobStoreInterface::class))
                ->setArgument('$eventBus', new Reference(StandaloneEventBusInterface::class))
                ->setArgument('$clock', new Reference(SystemClock::class))
                ->setPublic(false);
        }

        // GC command always exists (so `vortos:audit:export:gc` is discoverable); the pass points
        // its $collector at the real collector when an object-store target is present, else null.
        $container->register(AuditExportGcCommand::class, AuditExportGcCommand::class)
            ->setArgument('$collector', null)
            ->addTag('console.command')
            ->setPublic(false);
    }

    /**
     * Wire archive-then-purge retention (P4). Registered only with DBAL present. The
     * sweeper + console command are wired only when a durable archive target exists
     * (vortos-object-store), because purge must never run without archiving first.
     *
     * @param array<string, mixed> $config
     */
    private function registerRetention(ContainerBuilder $container, array $config): void
    {
        if (!class_exists(Connection::class) || !$container->hasDefinition(DbalAuditStore::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(SystemClock::class, SystemClock::class)->setPublic(false);
        $container->register(StoredAuditEventSerializer::class, StoredAuditEventSerializer::class)->setPublic(false);

        $container->register(DbalAuditCheckpointStore::class, DbalAuditCheckpointStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'audit_checkpoints')
            ->setPublic(false);
        $container->setAlias(AuditCheckpointStoreInterface::class, DbalAuditCheckpointStore::class);

        $container->register(AuditRetentionPolicy::class, AuditRetentionPolicy::class)
            ->setArgument('$platformDays', (int) $config['retention_platform_days'])
            ->setArgument('$tenantDefaultDays', (int) $config['retention_tenant_days'])
            ->setArgument('$tenantOverrides', (array) $config['retention_tenant_overrides'])
            ->setPublic(false);

        // Durable archive target (ObjectStoreArchiveWriter + AuditRetentionSweeper) is wired by
        // AuditRetentionArchivePass, NOT here: whether the object-store alias exists can only be
        // known reliably AFTER every extension's load() has run, so the decision is deferred to a
        // compiler pass. These two config values are handed to the pass via parameters.
        $container->setParameter('vortos_audit.archive_key_prefix', (string) $config['archive_key_prefix']);
        $container->setParameter('vortos_audit.retention_batch_size', (int) $config['retention_batch_size']);

        // Command always exists (so `vortos:audit:retention` is discoverable); the pass points
        // its $sweeper at the real sweeper when an archive target is present, else it stays null
        // and the command refuses to run.
        $container->register(AuditRetentionCommand::class, AuditRetentionCommand::class)
            ->setArgument('$sweeper', null)
            ->addTag('console.command')
            ->setPublic(false);
    }

    /**
     * Wire the admin facade + permission catalog (P6). The facade is the one surface the
     * app's audit endpoints (platform console / org settings) call. The permission catalog
     * is registered only when vortos-authorization is installed.
     */
    private function registerAdmin(ContainerBuilder $container, string $hmacKey): void
    {
        if ($container->hasDefinition(DbalAuditStore::class)) {
            $container->register(AuditAdminService::class, AuditAdminService::class)
                ->setArgument('$query', new Reference(AuditQueryInterface::class))
                ->setArgument('$reader', new Reference(AuditReaderInterface::class))
                ->setArgument('$verifier', new Reference(AuditChainVerifier::class))
                ->setArgument('$hmacKey', $hmacKey)
                ->setArgument('$checkpoints', new Reference(AuditCheckpointStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(true);
        }

        // Permission catalog: only when vortos-authorization is present (the class extends
        // its AbstractPermissionCatalog, so referencing it otherwise would fail to autoload).
        if (class_exists('Vortos\Authorization\Permission\AbstractPermissionCatalog')) {
            $container->register(AuditPermissionCatalog::class, AuditPermissionCatalog::class)
                ->addTag('vortos.permission_catalog', ['resource' => 'audit'])
                ->setPublic(false);
        }
    }

    /**
     * Wire metrics + doctor (P7). Metric definitions register into the global registry
     * when vortos-metrics is present; AuditMetrics is null-safe when it isn't. The doctor
     * captures compile-time facts so `vortos:audit:doctor` reports the real wiring.
     *
     * @param array<string, mixed> $config
     */
    private function registerObservability(ContainerBuilder $container, array $config, string $hmacKey): void
    {
        $metricsIface = 'Vortos\Metrics\Contract\MetricsInterface';
        if (interface_exists('Vortos\Metrics\Definition\MetricDefinitionProviderInterface')) {
            $container->register(AuditMetricDefinitions::class, AuditMetricDefinitions::class)
                ->addTag('vortos.metric_definitions')
                ->setPublic(false);
        }

        $metricsRef = interface_exists($metricsIface) && ($container->has($metricsIface) || $container->hasAlias($metricsIface))
            ? new Reference($metricsIface)
            : null;
        $container->register(AuditMetrics::class, AuditMetrics::class)
            ->setArgument('$metrics', $metricsRef)
            ->setPublic(false);

        $container->register(AuditDoctor::class, AuditDoctor::class)
            ->setArgument('$facts', [
                'hmac_key_set'       => $hmacKey !== '',
                'async'              => (bool) $config['async'],
                'has_archive_target' => $container->hasAlias(AuditArchiveWriterInterface::class),
                'has_export_target'  => false, // flipped by AuditExportObjectStorePass when object store present
                'has_store'          => $container->hasDefinition(DbalAuditStore::class),
                'has_checkpoints'    => $container->hasAlias(AuditCheckpointStoreInterface::class),
                'row_level_security' => (bool) ($config['row_level_security'] ?? false),
                'search_driver'      => (string) ($config['search_driver'] ?? 'postgres_fts'),
                'auth_events_unified'=> (bool) ($config['auth_events_unify'] ?? false),
            ])
            ->setPublic(false);

        $container->register(AuditDoctorCommand::class, AuditDoctorCommand::class)
            ->setArgument('$doctor', new Reference(AuditDoctor::class))
            ->addTag('console.command')
            ->setPublic(false);
    }

    /**
     * True when the store runs on Postgres — read from the persistence write DSN. Defaults
     * to true (Postgres-first) when no DSN parameter is available at compile time, so the
     * advisory-lock strategy is chosen unless the app is demonstrably on another engine.
     */
    private function isPostgres(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter('vortos.persistence.write_dsn')) {
            return true;
        }

        $dsn = strtolower((string) $container->getParameter('vortos.persistence.write_dsn'));

        return $dsn === ''
            || str_starts_with($dsn, 'pgsql')
            || str_starts_with($dsn, 'postgres')
            || str_contains($dsn, 'postgresql');
    }

    /**
     * Builds the effective config from the fluent {@see VortosAuditConfig}.
     *
     * Loads config/audit.php then config/{env}/audit.php (env overrides base), each a closure
     * taking the config object — the same convention as vortos-scheduler/messaging. A legacy
     * `vortos_audit.strict` container parameter is still honoured (pre-fluent apps), and the
     * HMAC key is resolved from its referenced env var so the secret stays out of config.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(ContainerBuilder $container): array
    {
        $config = new VortosAuditConfig();

        if ($container->hasParameter('vortos_audit.strict')) {
            $config->strict((bool) $container->getParameter('vortos_audit.strict'));
        }

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDir = (string) $container->getParameter('kernel.project_dir');
            $env        = $container->hasParameter('kernel.env') ? (string) $container->getParameter('kernel.env') : 'prod';

            foreach (["{$projectDir}/config/audit.php", "{$projectDir}/config/{$env}/audit.php"] as $file) {
                if (is_file($file)) {
                    $loaded = require $file;
                    if ($loaded instanceof \Closure) {
                        $loaded($config);
                    } elseif (is_array($loaded)) {
                        // Back-compat: a plain-array config still merges its recognised keys.
                        $this->applyLegacyArray($config, $loaded);
                    }
                }
            }
        }

        $resolved             = $config->toArray();
        $resolved['hmac_key'] = $config->resolveHmacKey();

        return $resolved;
    }

    /**
     * Back-compat shim: map the old plain-array config shape onto the fluent object so an
     * app that hasn't migrated its config/audit.php keeps working.
     *
     * @param array<string, mixed> $a
     */
    private function applyLegacyArray(VortosAuditConfig $config, array $a): void
    {
        if (isset($a['strict']))       { $config->strict((bool) $a['strict']); }
        if (isset($a['async']))        { $config->async((bool) $a['async']); }
        if (isset($a['failure_mode'])) { $config->failureMode(FailureMode::tryFrom((string) $a['failure_mode']) ?? FailureMode::Block); }
        if (isset($a['idempotency_ttl_seconds'])) { $config->idempotencyTtl((int) $a['idempotency_ttl_seconds']); }
        if (isset($a['redis_dsn']))    { $config->redisDsn((string) $a['redis_dsn']); }
        if (isset($a['retention_platform_days'], $a['retention_tenant_days'])) {
            $config->retention((int) $a['retention_platform_days'], (int) $a['retention_tenant_days']);
        }
        foreach ((array) ($a['retention_tenant_overrides'] ?? []) as $tenantId => $days) {
            $config->retentionOverride((string) $tenantId, (int) $days);
        }
        if (isset($a['retention_batch_size'])) { $config->retentionBatchSize((int) $a['retention_batch_size']); }
        if (isset($a['archive_key_prefix']))   { $config->coldArchive((string) ($a['archive_bucket'] ?? ''), (string) $a['archive_key_prefix']); }
    }
}
