<?php

declare(strict_types=1);

namespace Vortos\Metrics\DependencyInjection;

use Prometheus\CollectorRegistry;
use Psr\Log\LoggerInterface;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Command\CollectMetricsCommand;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\Adapter\OpenTelemetryFlushListener;
use Vortos\Metrics\Adapter\OpenTelemetryMetrics;
use Vortos\Metrics\Adapter\PrometheusMetrics;
use Vortos\Metrics\Adapter\StatsDFlushListener;
use Vortos\Metrics\Adapter\StatsDMetrics;
use Vortos\Metrics\AutoInstrumentation\BackupMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\CacheMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\CqrsMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\HttpMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\HttpMetricsListener;
use Vortos\Metrics\AutoInstrumentation\MessagingMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\PersistenceMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\SecurityMetricDefinitions;
use Vortos\Metrics\AutoInstrumentation\SupervisorMetricDefinitions;
use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Decorator\ModuleAwareMetrics;
use Vortos\Metrics\Decorator\FailSafeMetrics;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistryFactory;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Http\MetricsController;
use Vortos\Metrics\OpenTelemetry\OpenTelemetryMetricsFactory;
use Vortos\Metrics\Command\SupervisorMetricsCommand;
use Vortos\Metrics\Supervisor\ProcSupervisorCommandRunner;
use Vortos\Metrics\Supervisor\SupervisorCommandRunnerInterface;
use Vortos\Metrics\Supervisor\SupervisorMetricsReporter;
use Vortos\Metrics\Supervisor\SupervisorStatusReader;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Metrics\Schedule\CollectOperationalMetricsCommand;
use Vortos\Metrics\Schedule\CollectOperationalMetricsHandler;
use Vortos\Metrics\Schedule\OperationalMetricsCollectSchedule;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;

/**
 * Wires all metrics services.
 *
 * Loads config/metrics.php then config/{env}/metrics.php (env overrides base).
 *
 * ## Services registered
 *
 *   MetricsInterface          — alias to the active adapter (NoOp by default)
 *   NoOpMetrics               — always registered (zero-overhead fallback)
 *   PrometheusMetrics         — registered when adapter = Prometheus
 *   StatsDMetrics             — registered when adapter = StatsD
 *   OpenTelemetryMetrics      — registered when adapter = OpenTelemetry
 *   CollectorRegistry         — registered when adapter = Prometheus
 *   MetricDefinitionRegistry  — validates names, types, labels, help, and buckets
 *   MetricsController         — registered with vortos.api.controller tag when Prometheus active
 *   HttpMetricsListener       — registered when MetricsModule::Http is enabled
 *   CqrsMetricsDecorator      — decorates CommandBusInterface when MetricsModule::Cqrs is enabled
 *   MessagingMetricsDecorator — decorates EventBusInterface when MetricsModule::Messaging is enabled
 *
 * ## Cache and Persistence metrics
 *
 * Cache and Persistence auto-instrumentation is applied by compiler passes registered
 * in MetricsPackage::build() — CacheMetricsCompilerPass and PersistenceMetricsCompilerPass.
 * These run after all extensions load, so they can safely find the active aliases.
 * MetricsExtension stores the disabled modules list as a container parameter so the
 * compiler passes can check whether to apply decoration.
 *
 * ## Default adapter
 *
 * Default is NoOp — zero overhead, no configuration required.
 * Switch to Prometheus or StatsD in config/metrics.php.
 *
 * ## Multi-process Prometheus (FrankenPHP)
 *
 * FrankenPHP runs multiple PHP workers. Use Redis-backed storage so all workers
 * share the same metric values:
 *
 *   $config->adapter(MetricsAdapter::Prometheus)->prometheusStorageRedis(prefix: 'metrics:');
 *
 * Requires vortos/vortos-cache with the Redis driver active.
 */
final class MetricsExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_metrics';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $config = new VortosMetricsConfig();

        $base = $projectDir . '/config/metrics.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/metrics.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $config->toArray();

        // Store disabled modules as a parameter so compiler passes can read it
        $disabledModuleValues = $this->moduleValues($resolved['disabled_modules']);
        $container->setParameter('vortos.metrics.disabled_modules', $disabledModuleValues);

        // Published for anything that must *predict* a metric name rather than emit one — the
        // dashboard generator above all. Without it that generator assumed the framework default
        // and produced dashboards querying series no configured deployment ever wrote.
        $container->setParameter('vortos.metrics.namespace', $resolved['namespace']);

        $container->register(MetricDefinitionRegistry::class, MetricDefinitionRegistry::class)
            ->setFactory([MetricDefinitionRegistryFactory::class, 'create'])
            ->setArgument('$definitions', $this->buildMetricDefinitions($resolved))
            ->setShared(true)
            ->setPublic(false);

        // Always register NoOp — fallback and test injection target
        $container->register(NoOpMetrics::class, NoOpMetrics::class)
            ->setArgument('$definitions', new Reference(MetricDefinitionRegistry::class))
            ->setShared(true)
            ->setPublic(false);

        /** @var MetricsAdapter $adapter */
        $adapter = $resolved['adapter'];

        match ($adapter) {
            MetricsAdapter::Prometheus => $this->registerPrometheus($container, $resolved),
            MetricsAdapter::StatsD     => $this->registerStatsD($container, $resolved),
            MetricsAdapter::OpenTelemetry => $this->registerOpenTelemetry($container, $resolved),
            MetricsAdapter::NoOp       => $this->registerNoOp($container),
        };

        $container->register(ModuleAwareMetrics::class, ModuleAwareMetrics::class)
            ->setArguments([
                new Reference('vortos.metrics.inner'),
                $disabledModuleValues,
            ])
            ->setShared(true)
            ->setPublic(false);

        // Recording a metric must never be able to change a business outcome. An undeclared metric
        // used to throw out of whatever business code recorded it — in a consumer that meant retry
        // x4 into the DLQ, so staff notifications were silently never delivered because of an
        // observability misconfiguration. FailSafeMetrics degrades the record path to a no-op in
        // production and still fails fast in dev/test, where the misconfiguration is cheap to fix.
        $container->register(FailSafeMetrics::class, FailSafeMetrics::class)
            ->setArgument('$inner', new Reference(ModuleAwareMetrics::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$environment', '%kernel.env%')
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(MetricsInterface::class, FailSafeMetrics::class)
            ->setPublic(true);

        $container->register(FrameworkTelemetry::class, FrameworkTelemetry::class)
            ->setArguments([
                new Reference(MetricsInterface::class),
                $disabledModuleValues,
            ])
            ->setShared(true)
            ->setPublic(false);

        $this->registerAutoInstrumentation($container, $resolved);
        $this->registerCollectorCommand($container);
        $this->registerCollectSchedule($container, $adapter);
        $this->registerSupervisorMetrics($container);
    }

    /**
     * In-container supervisord collector: program up/down, uptime, respawns and RSS.
     *
     * Registered unconditionally — with a NoOp adapter the reporter's telemetry no-ops, so the
     * command exists but is inert rather than absent and mysterious. The supervised-program
     * definition is added only when vortos-docker is installed, so `vortos:worker:install` writes
     * the stanza into every containerised app's supervisor config instead of each app remembering
     * to hand-add it.
     */
    private function registerSupervisorMetrics(ContainerBuilder $container): void
    {
        $container->register(ProcSupervisorCommandRunner::class, ProcSupervisorCommandRunner::class)
            ->setPublic(false);

        $container->setAlias(SupervisorCommandRunnerInterface::class, ProcSupervisorCommandRunner::class)
            ->setPublic(false);

        $container->register(SupervisorStatusReader::class, SupervisorStatusReader::class)
            ->setArgument('$runner', new Reference(SupervisorCommandRunnerInterface::class))
            ->setPublic(false);

        $container->register(SupervisorMetricsReporter::class, SupervisorMetricsReporter::class)
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)
            ->setPublic(false);

        $container->register(SupervisorMetricsCommand::class, SupervisorMetricsCommand::class)
            ->setArgument('$reader', new Reference(SupervisorStatusReader::class))
            ->setArgument('$reporter', new Reference(SupervisorMetricsReporter::class))
            ->setArgument('$metricsFlusher', new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
            ->setPublic(true);

        if (!class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)
            || !filter_var($_ENV['VORTOS_SUPERVISOR_METRICS_ENABLED'] ?? true, FILTER_VALIDATE_BOOL)
        ) {
            return;
        }

        $container->register('vortos.metrics.supervisor_process', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
            ->setArgument('$name', 'supervisor-metrics')
            ->setArgument('$command', \Vortos\Docker\Worker\WorkerConsole::command('vortos:metrics:supervisor --interval=15'))
            ->setArgument('$description', 'Publishes supervisord program state, restarts and memory as metrics')
            // Holds no messages and commits no offsets, so there is nothing to drain: a short stop
            // budget keeps it from delaying a worker-colour rollout.
            ->setArgument('$stopwaitsecs', 5)
            ->setArgument('$drainDeadline', 5)
            ->addTag(\Vortos\Docker\DependencyInjection\DockerExtension::WORKER_TAG)
            ->setPublic(false);
    }

    /**
     * Cadence for the operational gauges (outbox/DLQ backlog + oldest-age), which are pull-shaped:
     * something has to ASK for them. The Prometheus adapter already does — MetricsController runs
     * every tagged collector on each scrape — so a schedule there would only double the query load.
     * Push adapters have no scraper, so without this the gauges never reach the backend at all.
     *
     * Wired only when vortos-scheduler and vortos-cqrs are both installed; an app without them can
     * still drive the collectors with `vortos:metrics:collect` from its own cron.
     */
    private function registerCollectSchedule(ContainerBuilder $container, MetricsAdapter $adapter): void
    {
        $isPushAdapter = match ($adapter) {
            MetricsAdapter::OpenTelemetry, MetricsAdapter::StatsD => true,
            MetricsAdapter::Prometheus, MetricsAdapter::NoOp      => false,
        };

        if (!$isPushAdapter) {
            return;
        }

        // interface_exists / class_exists are pure autoload checks — reliable inside load(), unlike
        // container hasAlias() gates which read false under MergeExtensionConfigurationPass isolation.
        if (!interface_exists(CommandBusInterface::class) || !class_exists(StaticSchedulePass::class)) {
            return;
        }

        // Registered purely so SchedulableCommandPass's attribute scan (which only inspects
        // already-registered definitions) discovers #[SchedulableCommand]; CommandHydrator
        // instantiates the command by reflection, never via the container.
        $container->register(CollectOperationalMetricsCommand::class, CollectOperationalMetricsCommand::class)
            ->setPublic(false);

        $container->register(CollectOperationalMetricsHandler::class, CollectOperationalMetricsHandler::class)
            ->setArgument('$collectors', new TaggedIteratorArgument('vortos.metrics_collector'))
            ->setArgument('$metrics', new Reference(MetricsInterface::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('vortos.command_handler')
            ->setPublic(true);

        $container->register(OperationalMetricsCollectSchedule::class, OperationalMetricsCollectSchedule::class)
            ->addTag(StaticSchedulePass::TAG)
            ->setPublic(false);
    }

    private function registerNoOp(ContainerBuilder $container): void
    {
        $container->setAlias('vortos.metrics.inner', NoOpMetrics::class)
            ->setPublic(false);
    }

    private function registerPrometheus(ContainerBuilder $container, array $resolved): void
    {
        $env = $container->hasParameter('kernel.env') ? $container->getParameter('kernel.env') : 'prod';

        if ($env === 'prod'
            && $resolved['prometheus_endpoint_token'] === ''
            && !$resolved['prometheus_endpoint_open_access']
        ) {
            throw new \RuntimeException(
                'vortos-metrics: the Prometheus /metrics endpoint has no Bearer token in production. '
                . 'Call prometheusEndpointToken($_ENV[\'METRICS_TOKEN\']) in config/metrics.php, '
                . 'or call prometheusEndpointOpenAccess() to confirm network-level protection is in place.'
            );
        }

        $storage = match ($resolved['prometheus_storage']) {
            'redis' => $this->buildRedisStorage($container, $resolved),
            'apc'   => new Definition(APC::class),
            default => new Definition(InMemory::class),
        };

        $registryDef = new Definition(CollectorRegistry::class);
        $registryDef->setArguments([$storage]);
        $registryDef->setShared(true);
        $registryDef->setPublic(false);
        $container->setDefinition(CollectorRegistry::class, $registryDef);

        $container->register(PrometheusMetrics::class, PrometheusMetrics::class)
            ->setArguments([
                new Reference(CollectorRegistry::class),
                new Reference(MetricDefinitionRegistry::class),
                $resolved['namespace'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias('vortos.metrics.inner', PrometheusMetrics::class)
            ->setPublic(false);

        // Register the /metrics scrape endpoint
        $container->register(MetricsController::class, MetricsController::class)
            ->setArguments([
                new Reference(CollectorRegistry::class),
                $resolved['prometheus_endpoint_token'],
                new TaggedIteratorArgument('vortos.metrics_collector'),
            ])
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }

    private function registerCollectorCommand(ContainerBuilder $container): void
    {
        $container->register(CollectMetricsCommand::class, CollectMetricsCommand::class)
            ->setArguments([
                new TaggedIteratorArgument('vortos.metrics_collector'),
                new Reference(MetricsInterface::class),
            ])
            ->addTag('console.command')
            ->setPublic(true);
    }

    private function buildRedisStorage(ContainerBuilder $container, array $resolved): Definition
    {
        // Prometheus\Storage\Redis manages its own connection via options array.
        // Connection options may be overridden when constructing VortosMetricsConfig.
        $options = [
            'host'     => $resolved['prometheus_redis_host'],
            'port'     => $resolved['prometheus_redis_port'],
            'prefix'   => $resolved['prometheus_redis_prefix'],
        ];

        if ($resolved['prometheus_redis_password'] !== '') {
            $options['password'] = $resolved['prometheus_redis_password'];
        }

        $storageDef = new Definition(Redis::class);
        $storageDef->setArguments([$options]);

        return $storageDef;
    }

    private function registerStatsD(ContainerBuilder $container, array $resolved): void
    {
        $container->register(StatsDMetrics::class, StatsDMetrics::class)
            ->setArguments([
                new Reference(MetricDefinitionRegistry::class),
                $resolved['statsd_host'],
                $resolved['statsd_port'],
                $resolved['namespace'],
                $resolved['statsd_sample_rate'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias('vortos.metrics.inner', StatsDMetrics::class)
            ->setPublic(false);

        // Flush the StatsD buffer after each response (critical in FrankenPHP worker mode).
        $container->register(StatsDFlushListener::class, StatsDFlushListener::class)
            ->setArgument('$metrics', new Reference(StatsDMetrics::class))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);
    }

    private function registerOpenTelemetry(ContainerBuilder $container, array $resolved): void
    {
        $container->register(OpenTelemetryMetrics::class, OpenTelemetryMetrics::class)
            ->setFactory([OpenTelemetryMetricsFactory::class, 'create'])
            ->setArguments([
                [
                    'service_name' => $resolved['otlp_service_name'],
                    'service_version' => $resolved['otlp_service_version'],
                    'deployment_environment' => $resolved['otlp_deployment_environment'],
                    'service_instance_id' => $resolved['otlp_service_instance_id'] ?? '',
                    'endpoint' => $resolved['otlp_endpoint'],
                    'headers' => $resolved['otlp_headers'],
                    'timeout_ms' => $resolved['otlp_timeout_ms'],
                    'namespace' => $resolved['namespace'],
                    'temporality' => $resolved['otlp_temporality']->value,
                ],
                new Reference(MetricDefinitionRegistry::class),
                new Reference('vortos.logger.metrics', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias('vortos.metrics.inner', OpenTelemetryMetrics::class)
            ->setPublic(false);

        $container->register(OpenTelemetryFlushListener::class, OpenTelemetryFlushListener::class)
            ->setArgument('$metrics', new Reference(OpenTelemetryMetrics::class))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);
    }

    private function registerAutoInstrumentation(ContainerBuilder $container, array $resolved): void
    {
        $disabled = $this->moduleValues($resolved['disabled_modules']);

        if (!in_array(ObservabilityModule::Http->value, $disabled, true)) {
            $container->register(HttpMetricsListener::class, HttpMetricsListener::class)
                ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class))
                ->addTag('kernel.event_subscriber')
                ->setPublic(false);
        }

        // Cqrs and Messaging bus decoration are handled by CqrsMetricsCompilerPass
        // and MessagingMetricsCompilerPass: a hasAlias/hasDefinition check inside
        // load() runs against the per-extension merge container, where
        // CommandBusInterface/EventBusInterface are never visible. The passes run
        // after all extensions have merged, where the bus aliases are present.

        // Cache and Persistence auto-instrumentation are applied by compiler passes
        // (CacheMetricsCompilerPass, PersistenceMetricsCompilerPass) registered in MetricsPackage.
        // The passes read 'vortos.metrics.disabled_modules' to know whether to skip.

        $container->register('vortos.config_stub.metrics', ConfigStub::class)
            ->setArguments(['metrics', __DIR__ . '/../stubs/metrics.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);

        // Built-in metric definition providers — collected at compile time by MetricDefinitionsCompilerPass.
        // External modules (e.g. vortos-feature-flags) register their own providers with the same tag.
        foreach ([
            BackupMetricDefinitions::class,
            CacheMetricDefinitions::class,
            CqrsMetricDefinitions::class,
            HttpMetricDefinitions::class,
            MessagingMetricDefinitions::class,
            PersistenceMetricDefinitions::class,
            SecurityMetricDefinitions::class,
            SupervisorMetricDefinitions::class,
        ] as $providerClass) {
            $container->register($providerClass, $providerClass)
                ->addTag(MetricDefinitionProviderInterface::TAG)
                ->setPublic(false);
        }
    }

    /**
     * @param list<MetricsModule|ObservabilityModule|string> $modules
     * @return list<string>
     */
    private function moduleValues(array $modules): array
    {
        $values = [];
        foreach ($modules as $module) {
            if ($module instanceof MetricsModule) {
                $values[] = $module->observabilityModule()->value;
            } elseif ($module instanceof ObservabilityModule) {
                $values[] = $module->value;
            } else {
                $values[] = ObservabilityModule::fromLegacy((string) $module)->value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Converts user-configured metric definitions (from config/metrics.php) to the array
     * format expected by {@see MetricDefinitionRegistryFactory::create}.
     *
     * Built-in framework definitions are contributed by tagged {@see MetricDefinitionProviderInterface}
     * services registered in {@see registerAutoInstrumentation} and merged at compile time by
     * {@see MetricDefinitionsCompilerPass}. Module-level definitions (e.g. feature-flags) follow
     * the same tag pattern and are merged by the same pass.
     *
     * @return list<array{type: string, name: string, help: string, label_names: list<string>, buckets: list<float|int>}>
     */
    private function buildMetricDefinitions(array $resolved): array
    {
        return array_map(
            static fn (MetricDefinition $definition): array => $definition->toArray(),
            $resolved['metric_definitions'],
        );
    }
}
