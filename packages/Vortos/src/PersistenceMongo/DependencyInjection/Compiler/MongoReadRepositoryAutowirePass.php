<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection\Compiler;

use MongoDB\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Attribute\DisableMetrics;
use Vortos\PersistenceMongo\Read\MongoStore;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\MongoIndexAttributeScanner;
use Vortos\Tracing\Attribute\DisableTracing;

/**
 * Auto-wires MongoStore into read repositories that declare #[MongoCollection].
 *
 * For each service definition whose class carries #[MongoCollection]:
 *   1. Creates a named MongoStore service: vortos.mongo_store.<RepositoryClass>
 *      — wired with MongoDB\Client, databaseName, and the collection name
 *   2. Tags the MongoStore service with 'vortos.read_repository' so that
 *      MongoTracingCompilerPass and MongoCursorSecretCompilerPass inject their
 *      dependencies into the store (not the repository)
 *   3. Injects the store as the $store constructor argument of the repository
 *   4. Injects the PSR logger into the store for slow-query logging (if available)
 *   5. Registers the repository class with MongoIndexAttributeScanner so that
 *      vortos:mongo:sync discovers #[MongoIndex] attributes via reflection
 *
 * Respects #[DisableTracing] and #[DisableMetrics] on the repository class:
 *   — #[DisableTracing] skips the 'vortos.read_repository' tag on the store
 *     so MongoTracingCompilerPass does not inject the tracer
 *   — #[DisableMetrics] adds a 'vortos.skip_metrics' tag on the store
 *     so MongoMetricsCompilerPass skips metrics injection for that store
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 8 — before tracing (0), metrics (0),
 * and cursor-secret (0) passes so those passes find the tag we add here.
 *
 * Detection is attribute-based (#[MongoCollection]), not inheritance-based.
 * Any class — regardless of what it extends — is auto-wired if it carries
 * the attribute.
 */
final class MongoReadRepositoryAutowirePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Client::class)) {
            return;
        }

        $hasScanner = $container->hasDefinition(MongoIndexAttributeScanner::class);
        $hasLogger  = $container->hasAlias(LoggerInterface::class) || $container->hasDefinition(LoggerInterface::class);

        $thresholdMs = $container->hasParameter('vortos.persistence.slow_query_threshold_ms')
            ? (int) $container->getParameter('vortos.persistence.slow_query_threshold_ms')
            : 100;

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass      = new \ReflectionClass($className);
            $collectionAttr = $reflClass->getAttributes(MongoCollection::class)[0] ?? null;

            if ($collectionAttr === null) {
                continue;
            }

            $collectionName  = $collectionAttr->newInstance()->name;
            $disableTracing  = !empty($reflClass->getAttributes(DisableTracing::class));
            $disableMetrics  = !empty($reflClass->getAttributes(DisableMetrics::class));

            $storeId = 'vortos.mongo_store.' . $className;
            $storeDef = (new Definition(MongoStore::class))
                ->setArgument('$client', new Reference(Client::class))
                ->setArgument('$databaseName', '%vortos.persistence.mongo.database_name%')
                ->setArgument('$collectionName', $collectionName)
                ->setShared(true)
                ->setPublic(false);

            if (!$disableTracing) {
                $storeDef->addTag('vortos.read_repository');
            }

            if ($disableMetrics) {
                $storeDef->addTag('vortos.skip_metrics');
            }

            if ($hasLogger) {
                $storeDef->addMethodCall('setLogger', [new Reference(LoggerInterface::class), $thresholdMs]);
            }

            $container->setDefinition($storeId, $storeDef);

            $definition->setArgument('$store', new Reference($storeId));

            if ($hasScanner) {
                $container->getDefinition(MongoIndexAttributeScanner::class)
                    ->addMethodCall('addRepositoryClass', [$className]);
            }
        }
    }
}
