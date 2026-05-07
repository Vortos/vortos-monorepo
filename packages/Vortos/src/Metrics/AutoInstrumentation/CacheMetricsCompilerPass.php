<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Wraps the active cache adapter with CacheMetricsDecorator when metrics are enabled.
 *
 * Runs after CacheTracingCompilerPass (priority -10 vs 0) — so if tracing is also active,
 * the decoration chain is: Base → TracingCacheAdapter → CacheMetricsDecorator (outermost).
 * Metrics measure the total time including tracing overhead.
 *
 * Both CacheInterface and TaggedCacheInterface aliases are re-pointed to CacheMetricsDecorator.
 */
final class CacheMetricsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(MetricsInterface::class) && !$container->hasDefinition(MetricsInterface::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('cache', $disabled, true)) {
            return;
        }

        if (!$container->hasAlias(TaggedCacheInterface::class)) {
            return;
        }

        $innerServiceId = (string) $container->getAlias(TaggedCacheInterface::class);

        $container->register(CacheMetricsDecorator::class, CacheMetricsDecorator::class)
            ->setArguments([
                new Reference($innerServiceId),
                new Reference(MetricsInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(CacheInterface::class, CacheMetricsDecorator::class)
            ->setPublic(true);

        $container->setAlias(TaggedCacheInterface::class, CacheMetricsDecorator::class)
            ->setPublic(true);
    }
}
