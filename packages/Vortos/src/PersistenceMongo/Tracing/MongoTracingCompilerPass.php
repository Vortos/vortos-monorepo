<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Tracing;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Injects TracingInterface into all tagged read repositories.
 *
 * All services tagged 'vortos.read_repository' receive a setTracer() call
 * at compile time. At runtime, MongoReadRepository::setTracer() stores
 * the tracer and wraps key operations in spans.
 *
 * When TracingModule::Persistence is disabled via VortosTracingConfig::disable(),
 * ModuleAwareTracer returns NoOpSpan for every startSpan() call — the overhead
 * is a single method call, effectively zero.
 */
final class MongoTracingCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(TracingInterface::class) && !$container->hasDefinition(TracingInterface::class)) {
            return;
        }

        $taggedServices = $container->findTaggedServiceIds('vortos.read_repository');

        foreach (array_keys($taggedServices) as $serviceId) {
            $container->getDefinition($serviceId)
                ->addMethodCall('setTracer', [new Reference(TracingInterface::class)]);
        }
    }
}
