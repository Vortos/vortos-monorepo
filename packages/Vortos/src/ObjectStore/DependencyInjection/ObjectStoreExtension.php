<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\ObjectStore\Command\ObjectStoreOutboxRelayCommand;
use Vortos\ObjectStore\Command\ObjectStoreOutboxRetryCommand;
use Vortos\ObjectStore\Command\ObjectStoreHeadCommand;
use Vortos\ObjectStore\Command\ObjectStoreLifecycleCommand;
use Vortos\ObjectStore\Command\ObjectStoreMultipartCommand;
use Vortos\ObjectStore\Command\ObjectStorePresignCommand;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ImmediateDirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\ObjectStore\Contract\LifecycleManagerInterface;
use Vortos\ObjectStore\Contract\ServerSideMultipartMaintenanceInterface;
use Vortos\ObjectStore\Contract\ServerSideMultipartUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectOutboxWriterInterface;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\Contract\ObjectStoreRouterInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreObserverInterface;
use Vortos\ObjectStore\Contract\PublicUrlGeneratorInterface;
use Vortos\ObjectStore\Contract\StandaloneDirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\StandaloneObjectStoreInterface;
use Vortos\ObjectStore\DirectUpload\NullDirectUploadManager;
use Vortos\ObjectStore\DirectUpload\ImmediateDirectUploadManager;
use Vortos\ObjectStore\DirectUpload\S3DirectUploadManager;
use Vortos\ObjectStore\DirectUpload\StandaloneDirectUploadManager;
use Vortos\ObjectStore\DirectUpload\TransactionalOutboxDirectUploadManager;
use Vortos\ObjectStore\Failover\CircuitBreaker;
use Vortos\ObjectStore\Failover\CircuitBreakerObjectStore;
use Vortos\ObjectStore\Driver\Log\LogObjectStore;
use Vortos\ObjectStore\Driver\ImmediateObjectStore;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\Driver\S3\S3ClientFactory;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
use Vortos\ObjectStore\Middleware\HookMiddleware;
use Vortos\ObjectStore\Middleware\LoggingMiddleware;
use Vortos\ObjectStore\Middleware\MetricsMiddleware;
use Vortos\ObjectStore\Middleware\ObjectStoreMiddlewareStack;
use Vortos\ObjectStore\Middleware\PresignedUrlPolicyMiddleware;
use Vortos\ObjectStore\Middleware\SizeLimitMiddleware;
use Vortos\ObjectStore\Middleware\TracingMiddleware;
use Vortos\ObjectStore\Metrics\ObjectStoreMetricDefinitions;
use Vortos\ObjectStore\Multipart\S3ServerSideMultipartMaintenance;
use Vortos\ObjectStore\Multipart\S3ServerSideMultipartUploadManager;
use Vortos\ObjectStore\Health\S3ObjectStoreHealthCheck;
use Vortos\ObjectStore\Lifecycle\NullLifecycleManager;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;
use Vortos\ObjectStore\Outbox\ObjectOperationSerializer;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRelay;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRelayInterface;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRetryStore;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRetryStoreInterface;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxWriter;
use Vortos\ObjectStore\Outbox\StandaloneObjectStore;
use Vortos\ObjectStore\Outbox\TransactionalOutboxObjectStore;
use Vortos\ObjectStore\Policy\NoOpObjectPromotionPolicy;
use Vortos\ObjectStore\Router\SingleObjectStoreRouter;
use Vortos\ObjectStore\Url\PublicUrlBuilder;
use Vortos\Tracing\Contract\TracingInterface;

final class ObjectStoreExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_object_store';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $config = new VortosObjectStoreConfig();

        $base = $projectDir . '/config/object_store.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/object_store.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $resolved['outbox']['table_name'] = $prefix . $resolved['outbox']['table_name'];

        $this->setParameters($container, $resolved);
        $this->registerDrivers($container, $resolved);
        $this->registerPromotionPolicy($container);
        $this->registerMiddlewareStack($container, $resolved);
        $this->registerDirectUploads($container, $resolved);
        $this->registerMultipartUploads($container, $resolved);
        $this->registerOutbox($container, $resolved);
        $this->registerRouter($container);
        $this->registerPublicUrls($container, $resolved);
        $this->registerLifecycle($container, $resolved);
        $this->registerDiagnostics($container, $resolved);

        if (class_exists(ConfigExtension::class)) {
            $container->register('vortos.config_stub.object_store', ConfigStub::class)
                ->setArguments(['object_store', __DIR__ . '/../stubs/object_store.php'])
                ->addTag(ConfigExtension::STUB_TAG)
                ->setPublic(false);
        }
    }

    private function registerDrivers(ContainerBuilder $container, array $config): void
    {
        $container->register(NullObjectStore::class, NullObjectStore::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(LogObjectStore::class, LogObjectStore::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setArgument('$bucketName', $config['bucket']['name'])
            ->setShared(true)
            ->setPublic(false);

        if ($config['driver'] === 's3') {
            $container->register(\Aws\S3\S3Client::class, \Aws\S3\S3Client::class)
                ->setFactory([S3ClientFactory::class, 'create'])
                ->setArguments([
                    $config['provider'],
                    $config['region'],
                    $config['client']['endpoint'],
                    $config['client']['account_id'],
                    $config['client']['access_key_id'],
                    $config['client']['secret_access_key'],
                    $config['client']['http_timeout'],
                    $config['client']['connect_timeout'],
                    $config['client']['max_retries'],
                    $config['client']['path_style_endpoint'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(S3CompatibleObjectStore::class, S3CompatibleObjectStore::class)
                ->setArguments([
                    new Reference(\Aws\S3\S3Client::class),
                    $config['bucket']['name'],
                    $config['provider'],
                    $config['multipart']['part_size_bytes'],
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        $rawDriverClass = match ($config['driver']) {
            's3'  => S3CompatibleObjectStore::class,
            'null' => NullObjectStore::class,
            default => LogObjectStore::class,
        };

        if ($config['circuit_breaker']['enabled']) {
            $container->register(CircuitBreaker::class, CircuitBreaker::class)
                ->setArguments([
                    $config['circuit_breaker']['failure_threshold'],
                    $config['circuit_breaker']['reset_timeout_seconds'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(CircuitBreakerObjectStore::class, CircuitBreakerObjectStore::class)
                ->setArguments([
                    new Reference($rawDriverClass),
                    new Reference(CircuitBreaker::class),
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias('vortos_object_store.driver', CircuitBreakerObjectStore::class)->setPublic(false);
        } else {
            $container->setAlias('vortos_object_store.driver', $rawDriverClass)->setPublic(false);
        }
    }

    private function registerMiddlewareStack(ContainerBuilder $container, array $config): void
    {
        $container->registerForAutoconfiguration(ObjectStoreMiddlewareInterface::class)
            ->addTag('vortos_object_store.middleware');

        $container->registerForAutoconfiguration(ObjectStoreObserverInterface::class)
            ->addTag('vortos_object_store.observer');

        $container->register(SizeLimitMiddleware::class, SizeLimitMiddleware::class)
            ->setArgument('$maxUploadSizeBytes', $config['bucket']['max_upload_size_bytes'])
            ->addTag('vortos_object_store.middleware', ['priority' => 900])
            ->setShared(true)
            ->setPublic(false);

        // A default ClockInterface (NativeClock) is provided by
        // ObjectStoreRuntimeDependenciesPass when nothing else supplies one: a
        // has(ClockInterface) check here runs against the per-extension merge
        // container and never sees a clock registered by the app or another package.
        $container->register(PresignedUrlPolicyMiddleware::class, PresignedUrlPolicyMiddleware::class)
            ->setArgument('$maxPresignTtlSeconds', $config['bucket']['max_presign_ttl_seconds'])
            ->setArgument('$maxUploadSizeBytes', $config['bucket']['max_upload_size_bytes'])
            ->setArgument('$clock', new Reference(\Psr\Clock\ClockInterface::class))
            ->addTag('vortos_object_store.middleware', ['priority' => 875])
            ->setShared(true)
            ->setPublic(false);

        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setArgument('$observers', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->addTag('vortos_object_store.middleware', ['priority' => 700])
            ->setShared(true)
            ->setPublic(false);

        if ($config['observability']['tracing'] && interface_exists(TracingInterface::class)) {
            $container->register(TracingMiddleware::class, TracingMiddleware::class)
                ->setArgument('$tracer', new Reference(TracingInterface::class))
                ->setArgument('$disabledSections', $config['observability']['tracing_disabled_for'])
                ->addTag('vortos_object_store.middleware', ['priority' => 800])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($config['observability']['metrics'] && interface_exists(MetricsInterface::class)) {
            $container->register(MetricsMiddleware::class, MetricsMiddleware::class)
                ->setArgument('$metrics', new Reference(MetricsInterface::class))
                ->setArgument('$disabledSections', $config['observability']['metrics_disabled_for'])
                ->addTag('vortos_object_store.middleware', ['priority' => 650])
                ->setShared(true)
                ->setPublic(false);

            if (interface_exists(MetricDefinitionProviderInterface::class)) {
                $container->register(ObjectStoreMetricDefinitions::class, ObjectStoreMetricDefinitions::class)
                    ->setShared(true)
                    ->setPublic(false);

                // The Metrics package's MetricDefinitionRegistry (MetricsExtension::load)
                // is never visible here due to merge isolation;
                // ObjectStoreRuntimeDependenciesPass appends our metric definitions
                // to it after the container is merged.
            }
        }

        if ($config['observability']['logging']) {
            $container->register(LoggingMiddleware::class, LoggingMiddleware::class)
                ->setArgument('$logger', new Reference(LoggerInterface::class))
                ->setArgument('$disabledSections', $config['observability']['logging_disabled_for'])
                ->addTag('vortos_object_store.middleware', ['priority' => 100])
                ->setShared(true)
                ->setPublic(false);
        }

        $container->register(ObjectStoreMiddlewareStack::class, ObjectStoreMiddlewareStack::class)
            ->setArgument('$driver', new Reference('vortos_object_store.driver'))
            ->setArgument('$middlewares', [])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias('vortos_object_store.sending_store', ObjectStoreMiddlewareStack::class)->setPublic(false);

        $container->register(ImmediateObjectStore::class, ImmediateObjectStore::class)
            ->setArgument('$inner', new Reference('vortos_object_store.sending_store'))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ImmediateObjectStoreInterface::class, ImmediateObjectStore::class)->setPublic(false);
    }

    private function registerDirectUploads(ContainerBuilder $container, array $config): void
    {
        if ($config['driver'] === 's3') {
            $container->register(S3DirectUploadManager::class, S3DirectUploadManager::class)
                ->setArguments([
                    new Reference(\Aws\S3\S3Client::class),
                    $config['bucket']['name'],
                    new Reference('vortos_object_store.sending_store'),
                    $config['bucket']['temporary_key_prefix'],
                    new Reference(ObjectPromotionPolicyInterface::class),
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias('vortos_object_store.direct_upload_manager', S3DirectUploadManager::class)->setPublic(false);
            $container->setAlias(DirectUploadManagerInterface::class, S3DirectUploadManager::class)->setPublic(false);
            $this->registerImmediateDirectUploadManager($container);
            return;
        }

        $container->register(NullDirectUploadManager::class, NullDirectUploadManager::class)
            ->setArguments([
                new Reference('vortos_object_store.sending_store'),
                $config['bucket']['temporary_key_prefix'],
                new Reference(ObjectPromotionPolicyInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias('vortos_object_store.direct_upload_manager', NullDirectUploadManager::class)->setPublic(false);
        $container->setAlias(DirectUploadManagerInterface::class, NullDirectUploadManager::class)->setPublic(false);
        $this->registerImmediateDirectUploadManager($container);
    }

    private function registerPromotionPolicy(ContainerBuilder $container): void
    {
        $container->register(NoOpObjectPromotionPolicy::class, NoOpObjectPromotionPolicy::class)
            ->setShared(true)
            ->setPublic(false);

        // The default ObjectPromotionPolicyInterface alias is set by
        // ObjectStoreRuntimeDependenciesPass so it yields to an app-provided
        // override; a hasAlias/hasDefinition check here cannot see the app's
        // definition (merge isolation).
    }

    private function registerRouter(ContainerBuilder $container): void
    {
        $container->register(SingleObjectStoreRouter::class, SingleObjectStoreRouter::class)
            ->setArgument('$objectStore', new Reference(ObjectStoreInterface::class))
            ->setShared(true)
            ->setPublic(false);

        // The default ObjectStoreRouterInterface alias is set by
        // ObjectStoreRuntimeDependenciesPass so it yields to an app-provided
        // override (merge isolation hides the app's definition here).
    }

    private function registerImmediateDirectUploadManager(ContainerBuilder $container): void
    {
        $container->register(ImmediateDirectUploadManager::class, ImmediateDirectUploadManager::class)
            ->setArgument('$inner', new Reference('vortos_object_store.direct_upload_manager'))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ImmediateDirectUploadManagerInterface::class, ImmediateDirectUploadManager::class)->setPublic(false);
    }

    private function registerOutbox(ContainerBuilder $container, array $config): void
    {
        if (!$config['outbox']['enabled']) {
            $container->setAlias(ObjectStoreInterface::class, 'vortos_object_store.sending_store')->setPublic(false);
            return;
        }

        $container->register(ObjectOperationSerializer::class, ObjectOperationSerializer::class)
            ->setArgument('$maxInlinePayloadBytes', $config['outbox']['max_inline_payload_bytes'])
            ->setShared(true)
            ->setPublic(false);

        $container->register(ObjectStoreOutboxWriter::class, ObjectStoreOutboxWriter::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference(ObjectOperationSerializer::class),
                $config['outbox']['table_name'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ObjectOutboxWriterInterface::class, ObjectStoreOutboxWriter::class)->setPublic(false);

        $container->register(TransactionalOutboxObjectStore::class, TransactionalOutboxObjectStore::class)
            ->setArguments([
                new Reference(ObjectOutboxWriterInterface::class),
                new Reference('vortos_object_store.sending_store'),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ObjectStoreInterface::class, TransactionalOutboxObjectStore::class)->setPublic(false);

        $container->register(TransactionalOutboxDirectUploadManager::class, TransactionalOutboxDirectUploadManager::class)
            ->setArguments([
                new Reference('vortos_object_store.direct_upload_manager'),
                new Reference(ObjectOutboxWriterInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(DirectUploadManagerInterface::class, TransactionalOutboxDirectUploadManager::class)->setPublic(false);

        $container->register(StandaloneObjectStore::class, StandaloneObjectStore::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference(ObjectStoreInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(StandaloneObjectStoreInterface::class, StandaloneObjectStore::class)->setPublic(false);

        $container->register(StandaloneDirectUploadManager::class, StandaloneDirectUploadManager::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference(DirectUploadManagerInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(StandaloneDirectUploadManagerInterface::class, StandaloneDirectUploadManager::class)->setPublic(false);

        $container->register(ObjectStoreOutboxRelay::class, ObjectStoreOutboxRelay::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference('vortos_object_store.sending_store'),
                new Reference('vortos_object_store.direct_upload_manager'),
                new Reference(ObjectOperationSerializer::class),
                new Reference(LoggerInterface::class),
                $config['outbox']['table_name'],
                $config['outbox']['batch_size'],
                $config['outbox']['max_delivery_attempts'],
                $config['outbox']['backoff_base_seconds'],
                $config['outbox']['backoff_cap_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ObjectStoreOutboxRelayInterface::class, ObjectStoreOutboxRelay::class)
            ->setPublic(false);

        $container->register(ObjectStoreOutboxRelayCommand::class, ObjectStoreOutboxRelayCommand::class)
            ->setArguments([
                new Reference(ObjectStoreOutboxRelay::class),
                $config['outbox']['sleep_seconds_when_empty'],
            ])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(ObjectStoreOutboxRetryStore::class, ObjectStoreOutboxRetryStore::class)
            ->setArguments([
                '$connection' => new Reference(Connection::class),
                '$tableName'  => $config['outbox']['table_name'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ObjectStoreOutboxRetryStoreInterface::class, ObjectStoreOutboxRetryStore::class)
            ->setPublic(false);

        $container->register(ObjectStoreOutboxRetryCommand::class, ObjectStoreOutboxRetryCommand::class)
            ->setArgument('$store', new Reference(ObjectStoreOutboxRetryStoreInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        if (class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)) {
            $container->register('vortos_object_store.worker.outbox_relay', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArguments([
                    'object-store-outbox-relay',
                    'php /var/www/html/bin/console vortos:object-store:relay',
                    'Relay pending object-store outbox operations.',
                ])
                ->addTag('vortos.worker')
                ->setPublic(false);
        }
    }

    private function registerMultipartUploads(ContainerBuilder $container, array $config): void
    {
        if ($config['driver'] !== 's3') {
            return;
        }

        $container->register(S3ServerSideMultipartUploadManager::class, S3ServerSideMultipartUploadManager::class)
            ->setArguments([
                new Reference(\Aws\S3\S3Client::class),
                new Reference('vortos_object_store.sending_store'),
                $config['bucket']['name'],
                $config['multipart']['threshold_bytes'],
                $config['multipart']['part_size_bytes'],
                $config['multipart']['abort_on_failure'],
                $config['multipart']['max_object_size_bytes'],
                $config['multipart']['max_inline_body_bytes'],
                $config['multipart']['max_attempts'],
                $config['multipart']['concurrency'],
                $config['multipart']['backoff_base_milliseconds'],
                $config['multipart']['backoff_cap_milliseconds'],
                $config['multipart']['checksum_algorithm'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ServerSideMultipartUploadManagerInterface::class, S3ServerSideMultipartUploadManager::class)->setPublic(false);

        $container->register(S3ServerSideMultipartMaintenance::class, S3ServerSideMultipartMaintenance::class)
            ->setArguments([
                new Reference(\Aws\S3\S3Client::class),
                $config['bucket']['name'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ServerSideMultipartMaintenanceInterface::class, S3ServerSideMultipartMaintenance::class)->setPublic(false);
    }

    private function registerPublicUrls(ContainerBuilder $container, array $config): void
    {
        $container->register(ProviderCapabilities::class, ProviderCapabilities::class)
            ->setFactory([ProviderCapabilities::class, 'forProvider'])
            ->setArguments([
                $config['provider'],
                $config['bucket']['public_base_url'] !== null && $config['bucket']['public_base_url'] !== '',
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PublicUrlBuilder::class, PublicUrlBuilder::class)
            ->setArgument('$publicBaseUrl', $config['bucket']['public_base_url'])
            ->setArgument('$keyPrefix', $config['bucket']['key_prefix'])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PublicUrlGeneratorInterface::class, PublicUrlBuilder::class)->setPublic(false);
    }

    private function registerLifecycle(ContainerBuilder $container, array $config): void
    {
        if (!$config['lifecycle']['enabled'] || $config['driver'] !== 's3') {
            $container->register(NullLifecycleManager::class, NullLifecycleManager::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(LifecycleManagerInterface::class, NullLifecycleManager::class)->setPublic(false);
            return;
        }

        $arguments = [
            '$client' => new Reference(\Aws\S3\S3Client::class),
            '$bucket' => $config['bucket']['name'],
            '$capabilities' => new Reference(ProviderCapabilities::class),
            '$logger' => new Reference(LoggerInterface::class),
            '$temporaryPrefix' => $config['bucket']['temporary_key_prefix'],
            '$orphanTtlSeconds' => $config['bucket']['orphan_ttl_seconds'],
            '$managedRuleId' => $config['lifecycle']['rule_id'],
            '$roundUpMinimumLifecycleDay' => $config['lifecycle']['round_up_minimum_lifecycle_day'],
            '$manageTemporaryUploads' => $config['lifecycle']['manage_temporary_uploads'],
            '$observabilityDisabledSections' => array_values(array_unique(array_merge(
                $config['observability']['logging_disabled_for'],
                $config['observability']['tracing_disabled_for'],
                $config['observability']['metrics_disabled_for'],
            ))),
        ];

        // $tracer / $metrics are injected by ObjectStoreRuntimeDependenciesPass when
        // the corresponding service is present: a has(TracingInterface)/has(MetricsInterface)
        // check here runs against the per-extension merge container and never sees
        // services registered by the Tracing/Metrics extensions. These params tell the
        // pass whether the config opts into each.
        $container->setParameter('vortos_object_store.lifecycle.wants_tracer', (bool) $config['observability']['tracing']);
        $container->setParameter('vortos_object_store.lifecycle.wants_metrics', (bool) $config['observability']['metrics']);

        $container->register(S3LifecycleManager::class, S3LifecycleManager::class)
            ->setArguments($arguments)
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(LifecycleManagerInterface::class, S3LifecycleManager::class)->setPublic(false);
    }

    private function registerDiagnostics(ContainerBuilder $container, array $config): void
    {
        $container->register(ObjectStoreHeadCommand::class, ObjectStoreHeadCommand::class)
            ->setArgument('$objectStore', new Reference(ImmediateObjectStoreInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(ObjectStorePresignCommand::class, ObjectStorePresignCommand::class)
            ->setArgument('$objectStore', new Reference(ImmediateObjectStoreInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(ObjectStoreLifecycleCommand::class, ObjectStoreLifecycleCommand::class)
            ->setArgument('$lifecycleManager', new Reference(LifecycleManagerInterface::class))
            ->setArgument('$requireConfirmation', $config['lifecycle']['require_confirmation'])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        if ($container->hasAlias(ServerSideMultipartMaintenanceInterface::class)) {
            $container->register(ObjectStoreMultipartCommand::class, ObjectStoreMultipartCommand::class)
                ->setArgument('$maintenance', new Reference(ServerSideMultipartMaintenanceInterface::class))
                ->addTag('console.command')
                ->setShared(true)
                ->setPublic(false);
        }

        if ($config['driver'] === 's3') {
            $container->register(S3ObjectStoreHealthCheck::class, S3ObjectStoreHealthCheck::class)
                ->setArgument('$client', new Reference(\Aws\S3\S3Client::class))
                ->setArgument('$bucket', $config['bucket']['name'])
                ->setArgument('$provider', $config['provider'])
                ->setArgument('$coldStartAttempts', (int) ($config['health']['cold_start_attempts'] ?? 3))
                ->setArgument('$coldStartBackoffMs', (int) ($config['health']['cold_start_backoff_milliseconds'] ?? 200))
                ->setShared(true)
                ->setPublic(false);
        }
    }

    private function setParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('vortos_object_store.driver', $config['driver']);
        $container->setParameter('vortos_object_store.provider', $config['provider']);
        $container->setParameter('vortos_object_store.region', $config['region']);
        $container->setParameter('vortos_object_store.client.endpoint', $config['client']['endpoint']);
        $container->setParameter('vortos_object_store.client.account_id', $config['client']['account_id']);
        $container->setParameter('vortos_object_store.client.access_key_id', $config['client']['access_key_id']);
        $container->setParameter('vortos_object_store.client.secret_access_key', $config['client']['secret_access_key']);
        $container->setParameter('vortos_object_store.client.http_timeout', $config['client']['http_timeout']);
        $container->setParameter('vortos_object_store.client.connect_timeout', $config['client']['connect_timeout']);
        $container->setParameter('vortos_object_store.client.max_retries', $config['client']['max_retries']);
        $container->setParameter('vortos_object_store.client.path_style_endpoint', $config['client']['path_style_endpoint']);
        $container->setParameter('vortos_object_store.bucket.name', $config['bucket']['name']);
        $container->setParameter('vortos_object_store.bucket.key_prefix', $config['bucket']['key_prefix']);
        $container->setParameter('vortos_object_store.bucket.temporary_key_prefix', $config['bucket']['temporary_key_prefix']);
        $container->setParameter('vortos_object_store.bucket.public_base_url', $config['bucket']['public_base_url']);
        $container->setParameter('vortos_object_store.bucket.max_upload_size_bytes', $config['bucket']['max_upload_size_bytes']);
        $container->setParameter('vortos_object_store.bucket.default_presign_ttl_seconds', $config['bucket']['default_presign_ttl_seconds']);
        $container->setParameter('vortos_object_store.bucket.max_presign_ttl_seconds', $config['bucket']['max_presign_ttl_seconds']);
        $container->setParameter('vortos_object_store.bucket.orphan_ttl_seconds', $config['bucket']['orphan_ttl_seconds']);
        $container->setParameter('vortos_object_store.retry.max_attempts', $config['retry']['max_attempts']);
        $container->setParameter('vortos_object_store.retry.backoff_base_milliseconds', $config['retry']['backoff_base_milliseconds']);
        $container->setParameter('vortos_object_store.retry.backoff_cap_milliseconds', $config['retry']['backoff_cap_milliseconds']);
        $container->setParameter('vortos_object_store.audit.enabled', $config['audit']['enabled']);
        $container->setParameter('vortos_object_store.audit.table_name', $config['audit']['table_name']);
        $container->setParameter('vortos_object_store.multipart.threshold_bytes', $config['multipart']['threshold_bytes']);
        $container->setParameter('vortos_object_store.multipart.part_size_bytes', $config['multipart']['part_size_bytes']);
        $container->setParameter('vortos_object_store.multipart.abort_on_failure', $config['multipart']['abort_on_failure']);
        $container->setParameter('vortos_object_store.multipart.max_object_size_bytes', $config['multipart']['max_object_size_bytes']);
        $container->setParameter('vortos_object_store.multipart.max_inline_body_bytes', $config['multipart']['max_inline_body_bytes']);
        $container->setParameter('vortos_object_store.multipart.max_attempts', $config['multipart']['max_attempts']);
        $container->setParameter('vortos_object_store.multipart.concurrency', $config['multipart']['concurrency']);
        $container->setParameter('vortos_object_store.multipart.backoff_base_milliseconds', $config['multipart']['backoff_base_milliseconds']);
        $container->setParameter('vortos_object_store.multipart.backoff_cap_milliseconds', $config['multipart']['backoff_cap_milliseconds']);
        $container->setParameter('vortos_object_store.multipart.checksum_algorithm', $config['multipart']['checksum_algorithm']);
        $container->setParameter('vortos_object_store.outbox.enabled', $config['outbox']['enabled']);
        $container->setParameter('vortos_object_store.outbox.table_name', $config['outbox']['table_name']);
        $container->setParameter('vortos_object_store.outbox.batch_size', $config['outbox']['batch_size']);
        $container->setParameter('vortos_object_store.outbox.sleep_seconds_when_empty', $config['outbox']['sleep_seconds_when_empty']);
        $container->setParameter('vortos_object_store.outbox.max_delivery_attempts', $config['outbox']['max_delivery_attempts']);
        $container->setParameter('vortos_object_store.outbox.backoff_base_seconds', $config['outbox']['backoff_base_seconds']);
        $container->setParameter('vortos_object_store.outbox.backoff_cap_seconds', $config['outbox']['backoff_cap_seconds']);
        $container->setParameter('vortos_object_store.outbox.max_inline_payload_bytes', $config['outbox']['max_inline_payload_bytes']);
        $container->setParameter('vortos_object_store.circuit_breaker.enabled',               $config['circuit_breaker']['enabled']);
        $container->setParameter('vortos_object_store.circuit_breaker.failure_threshold',     $config['circuit_breaker']['failure_threshold']);
        $container->setParameter('vortos_object_store.circuit_breaker.reset_timeout_seconds', $config['circuit_breaker']['reset_timeout_seconds']);
        $container->setParameter('vortos_object_store.lifecycle.enabled', $config['lifecycle']['enabled']);
        $container->setParameter('vortos_object_store.lifecycle.manage_temporary_uploads', $config['lifecycle']['manage_temporary_uploads']);
        $container->setParameter('vortos_object_store.lifecycle.rule_id', $config['lifecycle']['rule_id']);
        $container->setParameter('vortos_object_store.lifecycle.require_confirmation', $config['lifecycle']['require_confirmation']);
        $container->setParameter('vortos_object_store.lifecycle.round_up_minimum_lifecycle_day', $config['lifecycle']['round_up_minimum_lifecycle_day']);
        $container->setParameter('vortos_object_store.observability.logging', $config['observability']['logging']);
        $container->setParameter('vortos_object_store.observability.tracing', $config['observability']['tracing']);
        $container->setParameter('vortos_object_store.observability.metrics', $config['observability']['metrics']);
        $container->setParameter('vortos_object_store.observability.logging_disabled_for', $config['observability']['logging_disabled_for']);
        $container->setParameter('vortos_object_store.observability.tracing_disabled_for', $config['observability']['tracing_disabled_for']);
        $container->setParameter('vortos_object_store.observability.metrics_disabled_for', $config['observability']['metrics_disabled_for']);
    }
}
