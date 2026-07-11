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
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Recorder\NullAuditRecorder;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\Audit\Storage\Dbal\DbalAuditStore;
use Vortos\Messaging\Contract\EventBusInterface;

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

        $this->registerStorage($container, $hmacKey);
        $this->registerIngestion($container, $config);

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
    private function registerStorage(ContainerBuilder $container, string $hmacKey): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalAuditStore::class, DbalAuditStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setArgument('$hmacKey', $hmacKey)
            ->setArgument('$table', $prefix . 'audit_events')
            ->setPublic(false);

        // The DBAL store becomes the recorder + reader of record.
        $container->setAlias(AuditRecorderInterface::class, DbalAuditStore::class);
        $container->setAlias(AuditReaderInterface::class, DbalAuditStore::class);
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
            ->setPublic(false);

        $container->register(AuditIngestionHandler::class, AuditIngestionHandler::class)
            ->setArgument('$processor', new Reference(AuditIngestionProcessor::class))
            ->addTag('vortos.event_handler')
            ->setPublic(false);

        if (!$config['async']) {
            return; // Consumer side ready, but keep the synchronous recorder.
        }

        $container->register(AsyncAuditRecorder::class, AsyncAuditRecorder::class)
            ->setArgument('$eventBus', new Reference(EventBusInterface::class))
            ->setArgument('$failureMode', FailureMode::tryFrom($config['failure_mode']) ?? FailureMode::Block)
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // Producer becomes the recorder of record; the request path now enqueues.
        $container->setAlias(AuditRecorderInterface::class, AsyncAuditRecorder::class);
    }

    /**
     * Loads config/audit.php then config/{env}/audit.php (env overrides base), same
     * convention as the other framework extensions. Recognised keys: 'strict' (bool),
     * 'hmac_key' (string). The HMAC key also falls back to the VORTOS_AUDIT_HMAC_KEY env
     * var so it can be supplied as a secret without a config file.
     *
     * @return array{strict: bool, hmac_key: string}
     */
    private function loadConfig(ContainerBuilder $container): array
    {
        $resolved = [
            'strict'   => $container->hasParameter('vortos_audit.strict')
                ? (bool) $container->getParameter('vortos_audit.strict')
                : true,
            'hmac_key' => (string) ($_ENV['VORTOS_AUDIT_HMAC_KEY'] ?? getenv('VORTOS_AUDIT_HMAC_KEY') ?: ''),
            // Async ingestion (P3): opt-in. When true the app MUST declare a 'vortos.audit'
            // consumer pipeline; otherwise dispatched events have nowhere to land.
            'async'                  => false,
            'failure_mode'           => 'block',
            'idempotency_ttl_seconds'=> 604800,
            'redis_dsn'              => (string) ($_ENV['VORTOS_AUDIT_REDIS_DSN'] ?? getenv('VORTOS_AUDIT_REDIS_DSN') ?: ''),
        ];

        if (!$container->hasParameter('kernel.project_dir')) {
            return $resolved;
        }

        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $env        = $container->hasParameter('kernel.env') ? (string) $container->getParameter('kernel.env') : 'prod';

        foreach (["{$projectDir}/config/audit.php", "{$projectDir}/config/{$env}/audit.php"] as $file) {
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $resolved = [...$resolved, ...array_intersect_key($loaded, $resolved)];
                }
            }
        }

        return $resolved;
    }
}
