<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestInterceptorImpl;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestProcessor;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\DatabaseChangeRequestStorage;
use Vortos\FeatureFlags\ChangeRequest\Storage\DatabaseEnvironmentProtectionStorage;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\Command\FlagsProcessChangeRequestsCommand;
use Vortos\FeatureFlags\Command\FlagsEvaluateGuardrailsCommand;
use Vortos\FeatureFlags\Delivery\CircuitBreakerFlagStorage;
use Vortos\FeatureFlags\Delivery\FlagChangeNotifierInterface;
use Vortos\FeatureFlags\Delivery\PollingFlagChangeNotifier;
use Vortos\FeatureFlags\Command\FlagsDriftCommand;
use Vortos\FeatureFlags\Command\FlagsExportCommand;
use Vortos\FeatureFlags\Command\FlagsImportCommand;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\Explain\FlagOverrideService;
use Vortos\FeatureFlags\Explain\OverrideAwareFlagRegistry;
use Vortos\FeatureFlags\Http\FlagBootstrapController;
use Vortos\FeatureFlags\Http\FlagStreamController;
use Vortos\FeatureFlags\Http\Management\FlagPreviewController;
use Vortos\FeatureFlags\Http\Middleware\FlagOverrideMiddleware;
use Vortos\FeatureFlags\GitOps\FlagDefinitionExporter;
use Vortos\FeatureFlags\GitOps\FlagDefinitionImporter;
use Vortos\FeatureFlags\GitOps\GitOpsDriftService;
use Vortos\FeatureFlags\Guardrail\GuardrailConditionEvaluator;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicyService;
use Vortos\FeatureFlags\Guardrail\GuardrailWatcherService;
use Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface;
use Vortos\FeatureFlags\Guardrail\MetricSource\NullGuardrailMetricSource;
use Vortos\FeatureFlags\Guardrail\Storage\DatabaseGuardrailPolicyStorage;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Http\Management\ChangeRequestController;
use Vortos\FeatureFlags\Http\Management\GuardrailController;
use Vortos\FeatureFlags\Authz\Management\FlagManagementPermissionCatalog;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Authz\Management\NullManagementAuthzGate;
use Vortos\FeatureFlags\Authz\NullFlagAuthzGate;
use Vortos\FeatureFlags\Command\FlagsActivateCommand;
use Vortos\FeatureFlags\Command\FlagsAddRuleCommand;
use Vortos\FeatureFlags\Command\FlagsCodeRefScanCommand;
use Vortos\FeatureFlags\Command\FlagsCreateCommand;
use Vortos\FeatureFlags\Command\FlagsDeleteCommand;
use Vortos\FeatureFlags\Command\FlagsDisableCommand;
use Vortos\FeatureFlags\Command\FlagsDraftCommand;
use Vortos\FeatureFlags\Command\FlagsEnableCommand;
use Vortos\FeatureFlags\Command\FlagsListCommand;
use Vortos\FeatureFlags\Command\FlagsProjectCreateCommand;
use Vortos\FeatureFlags\Command\FlagsProjectDeleteCommand;
use Vortos\FeatureFlags\Command\FlagsProjectListCommand;
use Vortos\FeatureFlags\Command\FlagsPromoteCommand;
use Vortos\FeatureFlags\Command\FlagsSegmentCreateCommand;
use Vortos\FeatureFlags\Command\FlagsSegmentDeleteCommand;
use Vortos\FeatureFlags\Command\FlagsSegmentListCommand;
use Vortos\FeatureFlags\Command\FlagsSetExpiryCommand;
use Vortos\FeatureFlags\Command\FlagsSetOwnerCommand;
use Vortos\FeatureFlags\Command\FlagsShowCommand;
use Vortos\FeatureFlags\Command\FlagsStalenessReportCommand;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\DefaultFlagContextResolver;
use Vortos\FeatureFlags\Http\ExposureController;
use Vortos\FeatureFlags\Http\FeatureFlagMiddleware;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Http\FlagsController;
use Vortos\FeatureFlags\Http\Management\FlagHistoryController;
use Vortos\FeatureFlags\Http\Management\FlagInsightsController;
use Vortos\FeatureFlags\Http\Management\GitOpsController;
use Vortos\FeatureFlags\Http\Management\WebhookManagementController;
use Vortos\FeatureFlags\Http\Management\FlagManagementController;
use Vortos\FeatureFlags\Webhook\DatabaseWebhookStorage;
use Vortos\FeatureFlags\Webhook\SsrfGuard;
use Vortos\FeatureFlags\Webhook\WebhookStorageInterface;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\Management\Interceptor\NullChangeRequestInterceptor;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\Management\ProjectManagementController;
use Vortos\FeatureFlags\Http\Management\SegmentManagementController;
use Vortos\FeatureFlags\Http\Management\SdkKeyManagementController;
use Vortos\FeatureFlags\Http\Middleware\SdkKeyAuthMiddleware;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Metrics\FlagMetricDefinitions;
use Vortos\FeatureFlags\Metrics\InstrumentedFlagRegistry;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\FeatureFlags\Projection\FlagReadModelProjector;
use Vortos\FeatureFlags\Projection\FlagReadModelProjectorInterface;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\DbalFlagAuditLogRepository;
use Vortos\FeatureFlags\ReadModel\DbalFlagStateViewRepository;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\Resolution\EffectiveFlagResolverInterface;
use Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver;
use Vortos\FeatureFlags\Resolution\GlobalFlagResolver;
use Vortos\FeatureFlags\Resolution\TenantOverrideFlagResolver;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\FlagResolverInterface;
use Vortos\FeatureFlags\SegmentRegistry;
use Vortos\FeatureFlags\SegmentResolverInterface;
use Vortos\FeatureFlags\SdkKey\IpAllowlistChecker;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\FeatureFlags\SdkKey\Storage\DatabaseSdkKeyStorage;
use Vortos\FeatureFlags\SdkKey\Storage\SdkKeyStorageInterface;
use Vortos\FeatureFlags\Storage\DatabaseFlagEnvironmentStateStorage;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;
use Vortos\FeatureFlags\Storage\DatabaseProjectStorage;
use Vortos\FeatureFlags\Storage\DatabaseSegmentStorage;
use Vortos\FeatureFlags\Storage\DatabaseTenantFlagOverrideStorage;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;
use Vortos\FeatureFlags\Storage\RedisCachingStorage;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\FeatureFlags\Storage\TenantFlagOverrideStorageInterface;
use Vortos\FeatureFlags\StorageFlagResolver;
use Vortos\FeatureFlags\SystemClock;
use Vortos\FeatureFlags\Validation\FlagValidator;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tenant\TenantContext;
use Vortos\Tracing\Contract\TracingInterface;

final class FeatureFlagsExtension extends Extension
{
    /** Block 25 (additive): tag for optional {@see ExposureObserverInterface} services. */
    public const EXPOSURE_OBSERVER_TAG = 'vortos.feature_flags.exposure_observer';

    public function getAlias(): string
    {
        return 'vortos_feature_flags';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        // Block 10 — request-scoped environment context (ambient env for current request).
        $container->register(FlagScopeContext::class, FlagScopeContext::class)
            ->setShared(true)
            ->setPublic(false);

        // Block 11 — request-scoped project context (ambient project for current request).
        $container->register(ProjectContext::class, ProjectContext::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(DatabaseFlagStorage::class, DatabaseFlagStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flags')
            ->setPublic(false);

        // Registered with no cache backing here. FlagStorageCacheCompilerPass
        // patches the $cache and $redis arguments when a PSR-16 cache / Redis are
        // present: a has(CacheInterface)/has(Redis) check inside load() runs against
        // the per-extension merge container, where those services (registered by
        // CacheExtension/AuthExtension::load) are never visible. Without the pass the
        // refs are always null and feature flags are silently never cached.
        $container->register(RedisCachingStorage::class, RedisCachingStorage::class)
            ->setArguments([
                new Reference(DatabaseFlagStorage::class),
                null,
                60,
                'default',
                null,
            ])
            ->setPublic(false);

        $container->setAlias(FlagStorageInterface::class, RedisCachingStorage::class)
            ->setPublic(false);

        $container->register(DatabaseSegmentStorage::class, DatabaseSegmentStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_segments')
            ->setPublic(false);

        $container->setAlias(SegmentStorageInterface::class, DatabaseSegmentStorage::class)
            ->setPublic(false);

        // Webhook subscription storage + SSRF guard (outbound flag-event notifications).
        $container->register(SsrfGuard::class, SsrfGuard::class)->setPublic(false);
        $container->register(DatabaseWebhookStorage::class, DatabaseWebhookStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_webhooks')
            ->setPublic(false);
        $container->setAlias(WebhookStorageInterface::class, DatabaseWebhookStorage::class)
            ->setPublic(false);

        // Block 11 — project storage.
        $container->register(DatabaseProjectStorage::class, DatabaseProjectStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_projects')
            ->setPublic(false);

        $container->setAlias(ProjectStorageInterface::class, DatabaseProjectStorage::class)
            ->setPublic(false);

        // Request-scoped: bulk-loads segments once, memoized, filtered by active project.
        $container->register(SegmentRegistry::class, SegmentRegistry::class)
            ->setArgument('$storage', new Reference(SegmentStorageInterface::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(SegmentResolverInterface::class, SegmentRegistry::class)
            ->setPublic(false);

        // Request-scoped flag resolver for prerequisite evaluation (memoized, separate
        // from FlagRegistry to avoid a constructor cycle).
        $container->register(StorageFlagResolver::class, StorageFlagResolver::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(FlagResolverInterface::class, StorageFlagResolver::class)
            ->setPublic(false);

        $container->register(FlagValidator::class, FlagValidator::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setPublic(false);

        // Block 7 read models — relational (DBAL) by DEFAULT, so no second datastore is
        // required. FlagReadModelCompilerPass optionally repoints these to Mongo when a
        // MongoDB client is present; nothing forces Mongo on.
        $container->register(DbalFlagAuditLogRepository::class, DbalFlagAuditLogRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_audit_log')
            ->setPublic(false);

        $container->register(DbalFlagStateViewRepository::class, DbalFlagStateViewRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_state_view')
            ->setPublic(false);

        $container->setAlias(FlagAuditLogRepositoryInterface::class, DbalFlagAuditLogRepository::class)
            ->setPublic(false);
        $container->setAlias(FlagStateViewRepositoryInterface::class, DbalFlagStateViewRepository::class)
            ->setPublic(false);

        $container->register(FlagReadModelProjector::class, FlagReadModelProjector::class)
            ->setArgument('$auditLog', new Reference(FlagAuditLogRepositoryInterface::class))
            ->setArgument('$stateView', new Reference(FlagStateViewRepositoryInterface::class))
            ->setPublic(false);

        $container->setAlias(FlagReadModelProjectorInterface::class, FlagReadModelProjector::class)
            ->setPublic(false);

        // The single audited write boundary (Block 7/10/11). Every flag mutation goes through
        // here so it lands in the ledger; brackets the DomainEventLedger like the
        // CommandBus because flag writes originate from CLI / management, not a command.
        // Block 10: also writes per-env state. Block 11: tags new flags with active project.
        $container->register(FlagWriteService::class, FlagWriteService::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$unitOfWork', new Reference(UnitOfWorkInterface::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$projector', new Reference(FlagReadModelProjectorInterface::class))
            ->setArgument('$envStateStorage', new Reference(FlagEnvironmentStateStorageInterface::class))
            ->setArgument('$scope', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setPublic(false);

        // Block 12 — env state promotion service (copy targeting rules across envs).
        $container->register(FlagPromotionService::class, FlagPromotionService::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$envStateStorage', new Reference(FlagEnvironmentStateStorageInterface::class))
            ->setArgument('$unitOfWork', new Reference(UnitOfWorkInterface::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$projector', new Reference(FlagReadModelProjectorInterface::class))
            ->setPublic(false);

        $container->register(SystemClock::class, SystemClock::class)
            ->setPublic(false);

        $container->register(FlagEvaluator::class, FlagEvaluator::class)
            ->setArgument('$segments', new Reference(SegmentResolverInterface::class))
            ->setArgument('$flags', new Reference(FlagResolverInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setPublic(false);

        // Block 10 — per-environment flag state storage.
        $container->register(DatabaseFlagEnvironmentStateStorage::class, DatabaseFlagEnvironmentStateStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_environment_state')
            ->setPublic(false);

        $container->setAlias(FlagEnvironmentStateStorageInterface::class, DatabaseFlagEnvironmentStateStorage::class)
            ->setPublic(false);

        // Block 10 — environment-scoped base resolver (replaces GlobalFlagResolver in the chain).
        // Block 11 — also filters by active project.
        $container->register(EnvironmentScopedFlagResolver::class, EnvironmentScopedFlagResolver::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$envStates', new Reference(FlagEnvironmentStateStorageInterface::class))
            ->setArgument('$scope', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setShared(true)
            ->setPublic(false);

        // Keep GlobalFlagResolver registered for any legacy direct callers (unused in chain after B10).
        $container->register(GlobalFlagResolver::class, GlobalFlagResolver::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setPublic(false);

        // Block 9 — per-tenant override layer (resolution chain: tenant → env-scoped → fallback).
        $container->register(DatabaseTenantFlagOverrideStorage::class, DatabaseTenantFlagOverrideStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_tenant_overrides')
            ->setPublic(false);

        $container->setAlias(TenantFlagOverrideStorageInterface::class, DatabaseTenantFlagOverrideStorage::class)
            ->setPublic(false);

        // Tenant context is optional — when absent (or no tenant set) this is a pure
        // passthrough to the env-scoped resolver, so the common path adds no override query.
        $container->register(TenantOverrideFlagResolver::class, TenantOverrideFlagResolver::class)
            ->setArgument('$inner', new Reference(EnvironmentScopedFlagResolver::class))
            ->setArgument('$overrides', new Reference(TenantFlagOverrideStorageInterface::class))
            ->setArgument('$tenantContext', new Reference(TenantContext::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(EffectiveFlagResolverInterface::class, TenantOverrideFlagResolver::class)
            ->setPublic(false);

        // Block 9 — authz-scope gate. Default = no gating; FlagAuthzGateCompilerPass
        // upgrades this to the PolicyEngine-backed gate when Authorization is present.
        $container->register(NullFlagAuthzGate::class, NullFlagAuthzGate::class)
            ->setPublic(false);

        $container->setAlias(FlagAuthzGateInterface::class, NullFlagAuthzGate::class)
            ->setPublic(false);

        $container->register(FlagRegistry::class, FlagRegistry::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$evaluator', new Reference(FlagEvaluator::class))
            ->setArgument('$resolver', new Reference(EffectiveFlagResolverInterface::class))
            ->setArgument('$authz', new Reference(FlagAuthzGateInterface::class))
            ->setArgument('$scope', new Reference(FlagScopeContext::class))
            ->setShared(true)
            ->setPublic(true);

        // Block 8: evaluation metrics. MetricsInterface is optional — when absent the
        // helper no-ops (genuine zero cost on the hot path).
        $container->register(FlagEvaluationMetrics::class, FlagEvaluationMetrics::class)
            ->setArgument('$metrics', new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Declare the 4 flag metric names in the MetricDefinitionRegistry so any non-NoOp
        // backend accepts them without MetricNotDefinedException. Collected at compile time
        // by MetricDefinitionsCompilerPass via the vortos.metric_definitions tag.
        $container->register(FlagMetricDefinitions::class, FlagMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        // Transparent metrics decorator over the registry; the SDK endpoint + middleware
        // resolve the interface, so they go through instrumentation.
        $container->register(InstrumentedFlagRegistry::class, InstrumentedFlagRegistry::class)
            ->setArgument('$inner', new Reference(FlagRegistry::class))
            ->setArgument('$metrics', new Reference(FlagEvaluationMetrics::class))
            ->setShared(true)
            ->setPublic(true);

        $container->setAlias(FlagRegistryInterface::class, InstrumentedFlagRegistry::class)
            ->setPublic(true);

        $container->register(DefaultFlagContextResolver::class, DefaultFlagContextResolver::class)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setPublic(false);

        $container->setAlias(FlagContextResolverInterface::class, DefaultFlagContextResolver::class)
            ->setPublic(true);

        $container->register(FeatureFlagMiddleware::class, FeatureFlagMiddleware::class)
            ->setArgument('$registry', new Reference(FlagRegistryInterface::class))
            ->setArgument('$contextResolver', new Reference(FlagContextResolverInterface::class))
            ->setArgument('$flagMap', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$tracer', new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);

        $container->register(FlagsController::class, FlagsController::class)
            ->setArgument('$registry', new Reference(FlagRegistryInterface::class))
            ->setArgument('$contextResolver', new Reference(FlagContextResolverInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Block 8: exposure ingestion (closes the SDK exposureEndpoint loop).
        // Block 25 (additive): optional observers, notified once per accepted exposure.
        // Default is an empty iterable — existing behavior is unchanged when none are wired.
        $container->registerForAutoconfiguration(ExposureObserverInterface::class)
            ->addTag(self::EXPOSURE_OBSERVER_TAG);

        $container->register(ExposureIngestService::class, ExposureIngestService::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$metrics', new Reference(FlagEvaluationMetrics::class))
            ->setArgument('$observers', new TaggedIteratorArgument(self::EXPOSURE_OBSERVER_TAG))
            ->setPublic(false);

        $container->register(ExposureController::class, ExposureController::class)
            ->setArgument('$ingest', new Reference(ExposureIngestService::class))
            ->setArgument('$contextResolver', new Reference(FlagContextResolverInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Read-only commands: storage + scope + project.
        foreach ([
            FlagsListCommand::class,
            FlagsShowCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$storage', new Reference(FlagStorageInterface::class))
                ->setArgument('$scope', new Reference(FlagScopeContext::class))
                ->setArgument('$projectContext', new Reference(ProjectContext::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        // Mutating commands: storage + write boundary + scope + project.
        foreach ([
            FlagsCreateCommand::class,
            FlagsEnableCommand::class,
            FlagsDisableCommand::class,
            FlagsDeleteCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$storage', new Reference(FlagStorageInterface::class))
                ->setArgument('$writeService', new Reference(FlagWriteService::class))
                ->setArgument('$scope', new Reference(FlagScopeContext::class))
                ->setArgument('$projectContext', new Reference(ProjectContext::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        $container->register(FlagsAddRuleCommand::class, FlagsAddRuleCommand::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$validator', new Reference(FlagValidator::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$scope', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->addTag('console.command')
            ->setPublic(false);

        foreach ([
            FlagsSegmentCreateCommand::class,
            FlagsSegmentListCommand::class,
            FlagsSegmentDeleteCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$storage', new Reference(SegmentStorageInterface::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        // Block 11 — project management commands.
        foreach ([
            FlagsProjectCreateCommand::class,
            FlagsProjectListCommand::class,
            FlagsProjectDeleteCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$projects', new Reference(ProjectStorageInterface::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        // Block 12 — lifecycle mutation commands (need storage + write service + scope + project).
        foreach ([
            FlagsActivateCommand::class,
            FlagsDraftCommand::class,
            FlagsSetOwnerCommand::class,
            FlagsSetExpiryCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$storage', new Reference(FlagStorageInterface::class))
                ->setArgument('$writeService', new Reference(FlagWriteService::class))
                ->setArgument('$scope', new Reference(FlagScopeContext::class))
                ->setArgument('$projectContext', new Reference(ProjectContext::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        // Block 12 — promotion command (only needs FlagPromotionService).
        $container->register(FlagsPromoteCommand::class, FlagsPromoteCommand::class)
            ->setArgument('$promotionService', new Reference(FlagPromotionService::class))
            ->addTag('console.command')
            ->setPublic(false);

        // Block 12 — read-only report commands.
        $container->register(FlagsStalenessReportCommand::class, FlagsStalenessReportCommand::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(FlagsCodeRefScanCommand::class, FlagsCodeRefScanCommand::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->addTag('console.command')
            ->setPublic(false);

        // Block 13 — SDK keys.
        $container->register(DatabaseSdkKeyStorage::class, DatabaseSdkKeyStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_sdk_keys')
            ->setPublic(false);

        $container->setAlias(SdkKeyStorageInterface::class, DatabaseSdkKeyStorage::class)
            ->setPublic(false);

        $container->register(IpAllowlistChecker::class, IpAllowlistChecker::class)
            ->setPublic(false);

        $container->register(SdkKeyService::class, SdkKeyService::class)
            ->setArgument('$storage', new Reference(SdkKeyStorageInterface::class))
            ->setArgument('$ipChecker', new Reference(IpAllowlistChecker::class))
            ->setPublic(false);

        // Block 13 — SDK key authentication middleware (eval plane guard).
        // Implements Vortos\Http\Contract\MiddlewareInterface — HttpExtension's
        // registerForAutoconfiguration() already tags it 'vortos.http_middleware'.
        // Do NOT addTag('vortos.middleware') here: that's Messaging's middleware
        // tag, and its MiddlewareCompilerPass asserts every 'vortos.middleware'
        // service implements Messaging's own MiddlewareInterface — it doesn't.
        $container->register(SdkKeyAuthMiddleware::class, SdkKeyAuthMiddleware::class)
            ->setArgument('$sdkKeyService', new Reference(SdkKeyService::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$ipResolver', new Reference(IpResolverInterface::class))
            ->setPublic(false);

        // Block 13 — management authz gate. Default = null (allow all); compiler pass
        // upgrades to PolicyEngine-backed gate when Authorization is wired.
        $container->register(NullManagementAuthzGate::class, NullManagementAuthzGate::class)
            ->setPublic(false);

        $container->setAlias(ManagementAuthzGateInterface::class, NullManagementAuthzGate::class)
            ->setPublic(false);

        // Register the management permissions (flags.read.any / write.any / publish.any) so
        // the PolicyEngine gate can resolve them. The tag is inert unless Authorization is
        // installed (PermissionRegistryPass only runs there); apps grant these to their own
        // admin role. Without this the management API fails closed with unknown_permission.
        $container->register(FlagManagementPermissionCatalog::class, FlagManagementPermissionCatalog::class)
            ->addTag('vortos.permission_catalog', ['resource' => 'flags'])
            ->setPublic(false);

        // Block 13 — rate limiting. Cache is optional — null = disabled.
        $container->register(FlagRateLimitService::class, FlagRateLimitService::class)
            ->setArgument('$cache', new Reference(AtomicCacheInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Block 13 — management response factory.
        $container->register(ManagementResponseFactory::class, ManagementResponseFactory::class)
            ->setPublic(false);

        // Block 13 — change request interceptor stub (Block 14 replaces with real impl).
        $container->register(NullChangeRequestInterceptor::class, NullChangeRequestInterceptor::class)
            ->setPublic(false);

        $container->setAlias(ChangeRequestInterceptorInterface::class, NullChangeRequestInterceptor::class)
            ->setPublic(false);

        // Block 13 — management controllers.
        $container->register(FlagManagementController::class, FlagManagementController::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$promotionService', new Reference(FlagPromotionService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setArgument('$changeRequestInterceptor', new Reference(ChangeRequestInterceptorInterface::class))
            ->setArgument('$validator', new Reference(VortosValidator::class))
            ->setArgument('$envStateStorage', new Reference(FlagEnvironmentStateStorageInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagHistoryController::class, FlagHistoryController::class)
            ->setArgument('$auditLog', new Reference(FlagAuditLogRepositoryInterface::class))
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$changeRequestInterceptor', new Reference(ChangeRequestInterceptorInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(GitOpsController::class, GitOpsController::class)
            ->setArgument('$exporter', new Reference(FlagDefinitionExporter::class))
            ->setArgument('$importer', new Reference(FlagDefinitionImporter::class))
            ->setArgument('$drift', new Reference(GitOpsDriftService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagInsightsController::class, FlagInsightsController::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(WebhookManagementController::class, WebhookManagementController::class)
            ->setArgument('$storage', new Reference(WebhookStorageInterface::class))
            ->setArgument('$ssrf', new Reference(SsrfGuard::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(SegmentManagementController::class, SegmentManagementController::class)
            ->setArgument('$storage', new Reference(SegmentStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$validator', new Reference(VortosValidator::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ProjectManagementController::class, ProjectManagementController::class)
            ->setArgument('$storage', new Reference(ProjectStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(SdkKeyManagementController::class, SdkKeyManagementController::class)
            ->setArgument('$sdkKeyService', new Reference(SdkKeyService::class))
            ->setArgument('$sdkKeyStorage', new Reference(SdkKeyStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$validator', new Reference(VortosValidator::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Block 14 — change requests (4-eyes approvals + scheduled changes).
        $container->register(DatabaseEnvironmentProtectionStorage::class, DatabaseEnvironmentProtectionStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_environment_protection')
            ->setPublic(false);

        $container->setAlias(EnvironmentProtectionStorageInterface::class, DatabaseEnvironmentProtectionStorage::class)
            ->setPublic(false);

        $container->register(ChangeRequestPolicy::class, ChangeRequestPolicy::class)
            ->setArgument('$protectionStorage', new Reference(EnvironmentProtectionStorageInterface::class))
            ->setPublic(false);

        $container->register(DatabaseChangeRequestStorage::class, DatabaseChangeRequestStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_change_requests')
            ->setPublic(false);

        $container->setAlias(ChangeRequestStorageInterface::class, DatabaseChangeRequestStorage::class)
            ->setPublic(false);

        $container->register(ChangeRequestService::class, ChangeRequestService::class)
            ->setArgument('$storage', new Reference(ChangeRequestStorageInterface::class))
            ->setArgument('$policy', new Reference(ChangeRequestPolicy::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$promotionService', new Reference(FlagPromotionService::class))
            ->setArgument('$unitOfWork', new Reference(UnitOfWorkInterface::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setPublic(false);

        $container->register(ChangeRequestProcessor::class, ChangeRequestProcessor::class)
            ->setArgument('$storage', new Reference(ChangeRequestStorageInterface::class))
            ->setArgument('$service', new Reference(ChangeRequestService::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$unitOfWork', new Reference(UnitOfWorkInterface::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Real change-request interceptor — the compiler pass aliases the interface to this.
        $container->register(ChangeRequestInterceptorImpl::class, ChangeRequestInterceptorImpl::class)
            ->setArgument('$policy', new Reference(ChangeRequestPolicy::class))
            ->setPublic(false);

        $container->register(ChangeRequestController::class, ChangeRequestController::class)
            ->setArgument('$service', new Reference(ChangeRequestService::class))
            ->setArgument('$storage', new Reference(ChangeRequestStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$validator', new Reference(VortosValidator::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagsProcessChangeRequestsCommand::class, FlagsProcessChangeRequestsCommand::class)
            ->setArgument('$processor', new Reference(ChangeRequestProcessor::class))
            ->addTag('console.command')
            ->setPublic(false);

        // Block 15 — release guardrails / automated rollback.
        $container->register(DatabaseGuardrailPolicyStorage::class, DatabaseGuardrailPolicyStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'feature_flag_guardrail_policies')
            ->setPublic(false);

        $container->setAlias(GuardrailPolicyStorageInterface::class, DatabaseGuardrailPolicyStorage::class)
            ->setPublic(false);

        // Default metric source = null (returns null → never trips). A real source
        // (e.g. PrometheusGuardrailMetricSource) is wired by the host app when configured.
        $container->register(NullGuardrailMetricSource::class, NullGuardrailMetricSource::class)
            ->setPublic(false);

        $container->setAlias(GuardrailMetricSourceInterface::class, NullGuardrailMetricSource::class)
            ->setPublic(false);

        $container->register(GuardrailConditionEvaluator::class, GuardrailConditionEvaluator::class)
            ->setArgument('$metricSource', new Reference(GuardrailMetricSourceInterface::class))
            ->setPublic(false);

        $container->register(GuardrailPolicyService::class, GuardrailPolicyService::class)
            ->setArgument('$storage', new Reference(GuardrailPolicyStorageInterface::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setPublic(false);

        $container->register(GuardrailWatcherService::class, GuardrailWatcherService::class)
            ->setArgument('$storage', new Reference(GuardrailPolicyStorageInterface::class))
            ->setArgument('$conditionEvaluator', new Reference(GuardrailConditionEvaluator::class))
            ->setArgument('$metricSource', new Reference(GuardrailMetricSourceInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$flagStorage', new Reference(FlagStorageInterface::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setPublic(false);

        $container->register(GuardrailController::class, GuardrailController::class)
            ->setArgument('$service', new Reference(GuardrailPolicyService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$validator', new Reference(VortosValidator::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagsEvaluateGuardrailsCommand::class, FlagsEvaluateGuardrailsCommand::class)
            ->setArgument('$watcher', new Reference(GuardrailWatcherService::class))
            ->addTag('console.command')
            ->setPublic(false);

        // Block 16 — circuit breaker decorator (wraps RedisCachingStorage).
        $container->register(CircuitBreakerFlagStorage::class, CircuitBreakerFlagStorage::class)
            ->setArgument('$inner', new Reference(RedisCachingStorage::class))
            ->setArgument('$failureThreshold', 3)
            ->setArgument('$cooldownSeconds', 10.0)
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Re-point FlagStorageInterface to flow through the circuit breaker.
        $container->setAlias(FlagStorageInterface::class, CircuitBreakerFlagStorage::class)
            ->setPublic(false);

        // Block 16 — polling-based change notifier (fallback; Redis pub/sub wired by compiler pass when available).
        $container->register(PollingFlagChangeNotifier::class, PollingFlagChangeNotifier::class)
            ->setArgument('$registry', new Reference(FlagRegistry::class))
            ->setPublic(false);

        $container->setAlias(FlagChangeNotifierInterface::class, PollingFlagChangeNotifier::class)
            ->setPublic(false);

        // Block 16 — bootstrap snapshot endpoint (CDN-cacheable, context-free).
        $container->register(FlagBootstrapController::class, FlagBootstrapController::class)
            ->setArgument('$registry', new Reference(FlagRegistryInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Block 16 — SSE stream endpoint (live flag-change pushes).
        $container->register(FlagStreamController::class, FlagStreamController::class)
            ->setArgument('$registry', new Reference(FlagRegistryInterface::class))
            ->setArgument('$notifier', new Reference(FlagChangeNotifierInterface::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Block 17 — flags-as-code / GitOps: export, import, drift detection.
        $container->register(FlagDefinitionExporter::class, FlagDefinitionExporter::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setPublic(false);

        $container->register(FlagDefinitionImporter::class, FlagDefinitionImporter::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setPublic(false);

        $container->register(GitOpsDriftService::class, GitOpsDriftService::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setPublic(false);

        // Block 17 — GitOps CLI commands.
        $container->register(FlagsExportCommand::class, FlagsExportCommand::class)
            ->setArgument('$exporter', new Reference(FlagDefinitionExporter::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(FlagsImportCommand::class, FlagsImportCommand::class)
            ->setArgument('$importer', new Reference(FlagDefinitionImporter::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(FlagsDriftCommand::class, FlagsDriftCommand::class)
            ->setArgument('$driftService', new Reference(GitOpsDriftService::class))
            ->addTag('console.command')
            ->setPublic(false);

        // Block 19 — evaluation explainer (separate from hot-path evaluator).
        $container->register(EvaluationExplainer::class, EvaluationExplainer::class)
            ->setArgument('$segments', new Reference(SegmentResolverInterface::class))
            ->setArgument('$flags', new Reference(FlagResolverInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$authz', new Reference(FlagAuthzGateInterface::class))
            ->setPublic(false);

        // Block 19 — per-request override service (disabled by default; requires config).
        $container->register(FlagOverrideService::class, FlagOverrideService::class)
            ->setArgument('$enabled', false)
            ->setArgument('$secret', '')
            ->setArgument('$allowedEnvironments', ['development', 'staging', 'test'])
            ->setPublic(false);

        // Block 19 — override-aware registry decorator (sits between instrumented and outer alias).
        $container->register(OverrideAwareFlagRegistry::class, OverrideAwareFlagRegistry::class)
            ->setArgument('$inner', new Reference(InstrumentedFlagRegistry::class))
            ->setArgument('$overrides', new Reference(FlagOverrideService::class))
            ->setShared(true)
            ->setPublic(true);

        // Re-point the interface alias to flow through the override layer.
        $container->setAlias(FlagRegistryInterface::class, OverrideAwareFlagRegistry::class)
            ->setPublic(true);

        // Block 19 — override middleware (reads X-Vortos-Flag-Override header).
        // Implements Vortos\Http\Contract\MiddlewareInterface — auto-tagged
        // 'vortos.http_middleware' by HttpExtension's autoconfiguration. See the
        // note on SdkKeyAuthMiddleware above for why no manual addTag() belongs here.
        $container->register(FlagOverrideMiddleware::class, FlagOverrideMiddleware::class)
            ->setArgument('$overrideService', new Reference(FlagOverrideService::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setPublic(false);

        // Block 19 — preview controller (management plane, authz gated).
        $container->register(FlagPreviewController::class, FlagPreviewController::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$explainer', new Reference(EvaluationExplainer::class))
            ->setArgument('$resolver', new Reference(EffectiveFlagResolverInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$response', new Reference(ManagementResponseFactory::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }
}
