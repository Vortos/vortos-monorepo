<?php

declare(strict_types=1);

namespace Vortos\Metrics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Metrics\AutoInstrumentation\CacheMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\CqrsMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\OperationalMessagingMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\PersistenceMetricsCompilerPass;

/**
 * Metrics package — order 55.
 *
 * Load order relative to other packages:
 *   Cache (30) → Messaging (40) → Tracing (50) → Metrics (55) → Persistence (60) → DBAL (70)
 *
 * MetricsExtension loads at order 55 — after Tracing so TracingInterface is available,
 * before PersistenceDbal (70) so MetricsInterface is defined when DBAL extension runs.
 *
 * However, CommandBusInterface (Cqrs, order 90) and EventBusInterface (Messaging, order 40)
 * may or may not be registered when MetricsExtension loads. MetricsExtension performs a
 * hasAlias/hasDefinition check and skips decoration if the target is absent.
 *
 * The two compiler passes run after all extensions have loaded:
 *   - CacheMetricsCompilerPass (priority -10): wraps the active cache adapter
 *   - PersistenceMetricsCompilerPass (priority -10): injects metrics into DBAL middleware stack
 *
 * Priority -10 is lower than CacheTracingCompilerPass (priority 0), so if both tracing and
 * metrics are active, the decoration chain is:
 *   Base → TracingCacheAdapter → CacheMetricsDecorator (outermost)
 */
final class MetricsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MetricsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Priority -10 runs after CacheTracingCompilerPass (priority 0).
        // Decoration chain: Base → TracingCacheAdapter → CacheMetricsDecorator (outermost).
        $container->addCompilerPass(
            new CacheMetricsCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -10,
        );

        // CqrsPackage (order 90) loads after MetricsPackage (order 55).
        // CommandBusInterface is only registered after all extensions load.
        $container->addCompilerPass(
            new CqrsMetricsCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -10,
        );

        $container->addCompilerPass(
            new PersistenceMetricsCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -10,
        );

        $container->addCompilerPass(
            new OperationalMessagingMetricsCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -10,
        );
    }
}
