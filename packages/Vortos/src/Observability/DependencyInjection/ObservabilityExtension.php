<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Observability\Audit\AuditChainVerifier;
use Vortos\Observability\Audit\AuditExportService;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Observability\Audit\DbalDeployAuditViewRepository;
use Vortos\Observability\Audit\DeployAuditProjector;
use Vortos\Observability\Audit\DeployAuditViewRepositoryInterface;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Collector\CollectorConfigPublisher;
use Vortos\Observability\Collector\LogPipelineBuilder;
use Vortos\Observability\Collector\YamlWriter;
use Vortos\Observability\Command\AuditExportCommand;
use Vortos\Observability\Command\AuditVerifyCommand;
use Vortos\Observability\Command\EmitHeartbeatCommand;
use Vortos\Observability\Command\GenerateCollectorConfigCommand;
use Vortos\Observability\Command\ListObservabilityStacksCommand;
use Vortos\Observability\Command\MarkersDrainCommand;
use Vortos\Observability\Command\PublishObservabilityTemplatesCommand;
use Vortos\Observability\DependencyInjection\Compiler\CollectErrorSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMarkerEmittersPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsQueriesPass;
use Vortos\Observability\Query\Driver\NullMetricsQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Query\MetricsQueryRegistry;
use Vortos\Observability\Driver\Glitchtip\CurlErrorTransport;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Driver\Null\NullErrorSink;
use Vortos\Observability\Driver\Null\NullMetricsSink;
use Vortos\Observability\Heartbeat\HeartbeatEmitterInterface;
use Vortos\Observability\Heartbeat\HttpHeartbeatEmitter;
use Vortos\Observability\Marker\Driver\GrafanaOtlp\GrafanaOtlpMarkerEmitter;
use Vortos\Observability\Marker\Driver\Null\NullMarkerEmitter;
use Vortos\Observability\Marker\CurlMarkerTransport;
use Vortos\Observability\Marker\DeployMarkerSink;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\Observability\Marker\MarkerEmitterRegistry;
use Vortos\Observability\Marker\MarkerTransportInterface;
use Vortos\Observability\Marker\OutboxMarkerEmitter;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\Observability\Sink\ErrorSinkRegistry;
use Vortos\Observability\Sink\ErrorTransportInterface;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Sink\MetricsSinkRegistry;
use Vortos\Observability\Sink\OtlpProtocol;
use Vortos\Observability\Service\ObservabilityTemplatePublisher;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;
use Vortos\Observability\Canary\MetricsQueryCanaryAdapter;
use Vortos\Observability\Slo\SloArtifactRenderer;

final class ObservabilityExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_observability';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerTemplatePublisher($container);
        $this->registerSinkSeam($container);
        $this->registerQuerySeam($container);
        $this->registerDefaultDrivers($container);
        $this->registerCollector($container);
        $this->registerHeartbeat($container);
        $this->registerMarkerSeam($container);
        $this->registerAudit($container);
        $this->registerSlo($container);
        $this->registerCommands($container);

        $container->registerForAutoconfiguration(MetricsSinkInterface::class)
            ->addTag(CollectMetricsSinksPass::TAG);
        $container->registerForAutoconfiguration(ErrorSinkInterface::class)
            ->addTag(CollectErrorSinksPass::TAG);
        $container->registerForAutoconfiguration(MarkerEmitterInterface::class)
            ->addTag(CollectMarkerEmittersPass::TAG);
        $container->registerForAutoconfiguration(MetricsQueryInterface::class)
            ->addTag(CollectMetricsQueriesPass::TAG);
    }

    private function registerTemplatePublisher(ContainerBuilder $container): void
    {
        $container->register(ObservabilityTemplateRegistry::class, ObservabilityTemplateRegistry::class)
            ->setArgument('$root', __DIR__ . '/../Resources/observability')
            ->setPublic(false);

        $container->register(ObservabilityTemplatePublisher::class, ObservabilityTemplatePublisher::class)
            ->setArgument('$registry', new Reference(ObservabilityTemplateRegistry::class))
            ->setPublic(false);
    }

    private function registerQuerySeam(ContainerBuilder $container): void
    {
        $container->register(CollectMetricsQueriesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(MetricsQueryRegistry::class, MetricsQueryRegistry::class)
            ->setArgument('$drivers', new Reference(CollectMetricsQueriesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(NullMetricsQuery::class, NullMetricsQuery::class)
            ->addTag(CollectMetricsQueriesPass::TAG)
            ->setPublic(false);

        // Bridge to Deploy's CanaryMetricsPort seam (if Deploy package is present)
        if (interface_exists(\Vortos\Deploy\Canary\CanaryMetricsPort::class)) {
            $container->register(MetricsQueryCanaryAdapter::class, MetricsQueryCanaryAdapter::class)
                ->setArgument('$metricsQuery', new Reference(NullMetricsQuery::class))
                ->setPublic(false);

            $container->setAlias(\Vortos\Deploy\Canary\CanaryMetricsPort::class, MetricsQueryCanaryAdapter::class)
                ->setPublic(false);
        }
    }

    private function registerSinkSeam(ContainerBuilder $container): void
    {
        $container->register(CollectMetricsSinksPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);
        $container->register(CollectErrorSinksPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(MetricsSinkRegistry::class, MetricsSinkRegistry::class)
            ->setArgument('$drivers', new Reference(CollectMetricsSinksPass::LOCATOR_ID))
            ->setPublic(false);
        $container->register(ErrorSinkRegistry::class, ErrorSinkRegistry::class)
            ->setArgument('$drivers', new Reference(CollectErrorSinksPass::LOCATOR_ID))
            ->setPublic(false);
    }

    private function registerDefaultDrivers(ContainerBuilder $container): void
    {
        $protocol = OtlpProtocol::tryFrom((string) ($_ENV['OBSERVABILITY_GRAFANA_OTLP_PROTOCOL'] ?? 'http/protobuf'))
            ?? OtlpProtocol::HttpProtobuf;

        $container->register(GrafanaOtlpMetricsSink::class, GrafanaOtlpMetricsSink::class)
            ->setArgument('$host', (string) ($_ENV['OBSERVABILITY_GRAFANA_OTLP_HOST'] ?? 'otlp-gateway.example.invalid'))
            ->setArgument('$protocol', $protocol)
            ->setArgument('$tlsEnabled', (bool) ($_ENV['OBSERVABILITY_GRAFANA_OTLP_TLS'] ?? true))
            ->addTag(CollectMetricsSinksPass::TAG)
            ->setPublic(false);

        $container->register(NullMetricsSink::class, NullMetricsSink::class)
            ->addTag(CollectMetricsSinksPass::TAG)
            ->setPublic(false);

        // Error-sink spool + transport + drivers.
        $container->register('vortos.observability.error_spool', BoundedSpool::class)
            ->setArgument('$path', (string) ($_ENV['OBSERVABILITY_SPOOL_DIR'] ?? sys_get_temp_dir() . '/vortos-observability') . '/errors.spool')
            ->setArgument('$maxBytes', (int) ($_ENV['OBSERVABILITY_SPOOL_MAX_BYTES'] ?? 256 * 1024 * 1024))
            ->setPublic(false);

        $container->register(ErrorTransportInterface::class, CurlErrorTransport::class)
            ->setPublic(false);

        $container->register(GlitchtipErrorSink::class, GlitchtipErrorSink::class)
            ->setArgument('$spool', new Reference('vortos.observability.error_spool'))
            ->setArgument('$transport', new Reference(ErrorTransportInterface::class))
            ->addTag(CollectErrorSinksPass::TAG)
            ->setPublic(false);

        // Sentry driver — same ingest protocol as GlitchTip; select via OBSERVABILITY_ERROR_SINK=sentry.
        $container->register(\Vortos\Observability\Driver\Sentry\SentryErrorSink::class, \Vortos\Observability\Driver\Sentry\SentryErrorSink::class)
            ->setArgument('$spool', new Reference('vortos.observability.error_spool'))
            ->setArgument('$transport', new Reference(ErrorTransportInterface::class))
            ->addTag(CollectErrorSinksPass::TAG)
            ->setPublic(false);

        $container->register(NullErrorSink::class, NullErrorSink::class)
            ->addTag(CollectErrorSinksPass::TAG)
            ->setPublic(false);

        // The configured error sink, selectable with OBSERVABILITY_ERROR_SINK (null|glitchtip|
        // sentry), resolved from the collected drivers — mirrors OBSERVABILITY_METRICS_SINK.
        // Consumers inject this to report to "whatever the operator configured".
        $container->register('vortos.observability.selected_error_sink', ErrorSinkInterface::class)
            ->setFactory([new Reference(ErrorSinkRegistry::class), 'sink'])
            ->setArguments([(string) ($_ENV['OBSERVABILITY_ERROR_SINK'] ?? 'null')])
            ->setPublic(false);
        $container->setAlias(ErrorSinkInterface::class, 'vortos.observability.selected_error_sink')
            ->setPublic(false);
    }

    private function registerCollector(ContainerBuilder $container): void
    {
        $container->register(YamlWriter::class, YamlWriter::class)->setPublic(false);

        $container->register(CollectorConfigBuilder::class, CollectorConfigBuilder::class)
            ->setPublic(false);

        $container->register(CollectorConfigPublisher::class, CollectorConfigPublisher::class)
            ->setArgument('$registry', new Reference(MetricsSinkRegistry::class))
            ->setArgument('$builder', new Reference(CollectorConfigBuilder::class))
            ->setArgument('$yaml', new Reference(YamlWriter::class))
            ->setPublic(false);
    }

    private function registerHeartbeat(ContainerBuilder $container): void
    {
        $container->register(HeartbeatEmitterInterface::class, HttpHeartbeatEmitter::class)
            ->setPublic(false);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $container->register(ListObservabilityStacksCommand::class, ListObservabilityStacksCommand::class)
            ->setArgument('$registry', new Reference(ObservabilityTemplateRegistry::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(PublishObservabilityTemplatesCommand::class, PublishObservabilityTemplatesCommand::class)
            ->setArgument('$publisher', new Reference(ObservabilityTemplatePublisher::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(GenerateCollectorConfigCommand::class, GenerateCollectorConfigCommand::class)
            ->setArgument('$publisher', new Reference(CollectorConfigPublisher::class))
            ->setArgument('$defaultSink', (string) ($_ENV['OBSERVABILITY_METRICS_SINK'] ?? 'grafana'))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(EmitHeartbeatCommand::class, EmitHeartbeatCommand::class)
            ->setArgument('$emitter', new Reference(HeartbeatEmitterInterface::class))
            ->setArgument('$defaultMonitorKey', (string) ($_ENV['OBSERVABILITY_HEARTBEAT_KEY'] ?? 'vortos-app'))
            ->setPublic(true)
            ->addTag('console.command');

        if ($container->hasDefinition(AuditVerifyCommand::class)) {
            $container->getDefinition(AuditVerifyCommand::class)
                ->setPublic(true)
                ->addTag('console.command');
        }

        if ($container->hasDefinition(AuditExportCommand::class)) {
            $container->getDefinition(AuditExportCommand::class)
                ->setPublic(true)
                ->addTag('console.command');
        }

        $container->register(MarkersDrainCommand::class, MarkersDrainCommand::class)
            ->setArgument('$outbox', new Reference(OutboxMarkerEmitter::class))
            ->setPublic(true)
            ->addTag('console.command');
    }

    /**
     * Block 16: deploy marker emitter seam — same swappable-driver shape as the
     * metrics/error sinks. {@see OutboxMarkerEmitter} always wraps the selected
     * driver so a backend outage can never block or fail a deploy.
     */
    private function registerMarkerSeam(ContainerBuilder $container): void
    {
        $container->register(CollectMarkerEmittersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(MarkerEmitterRegistry::class, MarkerEmitterRegistry::class)
            ->setArgument('$drivers', new Reference(CollectMarkerEmittersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(MarkerTransportInterface::class, CurlMarkerTransport::class)
            ->setPublic(false);

        $container->register(GrafanaOtlpMarkerEmitter::class, GrafanaOtlpMarkerEmitter::class)
            ->setArgument('$endpointUrl', (string) ($_ENV['OBSERVABILITY_GRAFANA_MARKER_URL'] ?? 'https://otlp-gateway.example.invalid/v1/logs'))
            ->setArgument('$transport', new Reference(MarkerTransportInterface::class))
            ->addTag(CollectMarkerEmittersPass::TAG)
            ->setPublic(false);

        $container->register(NullMarkerEmitter::class, NullMarkerEmitter::class)
            ->addTag(CollectMarkerEmittersPass::TAG)
            ->setPublic(false);

        $container->register('vortos.observability.marker_spool', BoundedSpool::class)
            ->setArgument('$path', (string) ($_ENV['OBSERVABILITY_SPOOL_DIR'] ?? sys_get_temp_dir() . '/vortos-observability') . '/markers.spool')
            ->setArgument('$maxBytes', (int) ($_ENV['OBSERVABILITY_SPOOL_MAX_BYTES'] ?? 256 * 1024 * 1024))
            ->setPublic(false);

        $markerEmitterKey = (string) ($_ENV['OBSERVABILITY_MARKER_EMITTER'] ?? 'null');
        $innerEmitterId = $markerEmitterKey === 'grafana' ? GrafanaOtlpMarkerEmitter::class : NullMarkerEmitter::class;

        $container->register(OutboxMarkerEmitter::class, OutboxMarkerEmitter::class)
            ->setArgument('$inner', new Reference($innerEmitterId))
            ->setArgument('$spool', new Reference('vortos.observability.marker_spool'))
            ->setPublic(false);

        // Block 16: Deploy is an optional integration for Observability (Deploy
        // never depends on Observability — see Vortos\Deploy\Audit\DeployAuditSinkInterface).
        if (interface_exists(\Vortos\Deploy\Audit\DeployAuditSinkInterface::class)) {
            $container->register(DeployMarkerSink::class, DeployMarkerSink::class)
                ->setArgument('$emitter', new Reference(OutboxMarkerEmitter::class))
                ->setPublic(false);
        }
    }

    /**
     * Block 16: tamper-evident deploy audit ledger. Guarded on a DB connection being
     * available (same `$container->has()` discipline Deploy itself uses for its
     * optional migration/release stack) and on Deploy being installed (audit
     * entries are built from Deploy's domain events).
     */
    private function registerAudit(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Audit\DeployAuditSinkInterface::class)) {
            return;
        }
        if (!$container->has(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $auditTable = $prefix . 'observability_deploy_audit_log';
        $hmacKey = (string) ($_ENV['OBSERVABILITY_AUDIT_HMAC_KEY'] ?? '');

        $container->register(DbalDeployAuditViewRepository::class, DbalDeployAuditViewRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $auditTable)
            ->setPublic(false);

        $container->setAlias(DeployAuditViewRepositoryInterface::class, DbalDeployAuditViewRepository::class)
            ->setPublic(false);

        $container->register(AuditHashChain::class, AuditHashChain::class)->setPublic(false);
        $container->register(AuditChainVerifier::class, AuditChainVerifier::class)
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setPublic(false);

        if ($hmacKey !== '') {
            $container->register(DeployAuditProjector::class, DeployAuditProjector::class)
                ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
                ->setArgument('$hmacKey', $hmacKey)
                ->setArgument('$chain', new Reference(AuditHashChain::class))
                ->setPublic(false);

            $container->register(AuditExportService::class, AuditExportService::class)
                ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
                ->setArgument('$hmacKey', $hmacKey)
                ->setPublic(false);

            $container->register(AuditExportCommand::class, AuditExportCommand::class)
                ->setArgument('$exporter', new Reference(AuditExportService::class))
                ->setPublic(false);
        }

        $container->register(AuditVerifyCommand::class, AuditVerifyCommand::class)
            ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
            ->setArgument('$verifier', new Reference(AuditChainVerifier::class))
            ->setArgument('$hmacKey', $hmacKey)
            ->setPublic(false);
    }

    private function registerSlo(ContainerBuilder $container): void
    {
        $container->register(SloArtifactRenderer::class, SloArtifactRenderer::class)
            ->setPublic(false);

        $container->register(LogPipelineBuilder::class, LogPipelineBuilder::class)
            ->setPublic(false);
    }
}
