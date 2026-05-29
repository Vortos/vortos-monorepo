<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Metrics;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Injects FrameworkTelemetry into all MongoDB read store services.
 *
 * All services tagged 'vortos.read_repository' receive a setMetrics() call
 * at compile time — unless they are also tagged 'vortos.skip_metrics'
 * (set by MongoReadRepositoryAutowirePass when #[DisableMetrics] is present
 * on the repository class).
 *
 * At runtime, MongoStore::setMetrics() stores the telemetry reference and
 * records vortos_db_queries_total and vortos_db_query_duration_ms for every
 * MongoDB operation — matching the metrics DBAL already records.
 *
 * Only runs when FrameworkTelemetry is registered (metrics module active)
 * and 'persistence' is not in vortos.metrics.disabled_modules.
 */
final class MongoMetricsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FrameworkTelemetry::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('persistence', $disabled, true)) {
            return;
        }

        $taggedServices = $container->findTaggedServiceIds('vortos.read_repository');

        foreach (array_keys($taggedServices) as $serviceId) {
            $definition = $container->getDefinition($serviceId);

            if ($definition->hasTag('vortos.skip_metrics')) {
                continue;
            }

            $definition->addMethodCall('setMetrics', [new Reference(FrameworkTelemetry::class)]);
        }
    }
}
