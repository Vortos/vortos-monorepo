<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\AutoInstrumentation\CacheMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\CacheMetricsDecorator;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class CacheMetricsCompilerPassTest extends TestCase
{
    public function test_re_aliases_all_three_interfaces_to_metrics_decorator(): void
    {
        $container = $this->makeContainerWithAdapter();

        (new CacheMetricsCompilerPass())->process($container);

        $this->assertSame(CacheMetricsDecorator::class, (string) $container->getAlias(CacheInterface::class));
        $this->assertSame(CacheMetricsDecorator::class, (string) $container->getAlias(TaggedCacheInterface::class));
        $this->assertSame(CacheMetricsDecorator::class, (string) $container->getAlias(AtomicCacheInterface::class));
    }

    public function test_does_nothing_when_framework_telemetry_not_registered(): void
    {
        $container = new ContainerBuilder();
        $container->register(InMemoryAdapter::class, InMemoryAdapter::class);
        $container->setAlias(TaggedCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(AtomicCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setParameter('vortos.metrics.disabled_modules', []);

        (new CacheMetricsCompilerPass())->process($container);

        $this->assertSame(InMemoryAdapter::class, (string) $container->getAlias(AtomicCacheInterface::class));
        $this->assertFalse($container->hasDefinition(CacheMetricsDecorator::class));
    }

    public function test_does_nothing_when_cache_module_disabled(): void
    {
        $container = $this->makeContainerWithAdapter();
        $container->setParameter('vortos.metrics.disabled_modules', ['cache']);

        (new CacheMetricsCompilerPass())->process($container);

        $this->assertSame(InMemoryAdapter::class, (string) $container->getAlias(AtomicCacheInterface::class));
        $this->assertFalse($container->hasDefinition(CacheMetricsDecorator::class));
    }

    public function test_atomic_alias_tracks_through_decorator_chain(): void
    {
        $container = $this->makeContainerWithAdapter();

        (new CacheMetricsCompilerPass())->process($container);

        $atomicAlias = (string) $container->getAlias(AtomicCacheInterface::class);
        $this->assertSame(CacheMetricsDecorator::class, $atomicAlias);

        $definition = $container->getDefinition(CacheMetricsDecorator::class);
        $innerRef = (string) $definition->getArgument(0);
        $this->assertSame(InMemoryAdapter::class, $innerRef);
    }

    private function makeContainerWithAdapter(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(InMemoryAdapter::class, InMemoryAdapter::class);
        $container->register(NoOpMetrics::class, NoOpMetrics::class);
        $container->setAlias(MetricsInterface::class, NoOpMetrics::class)->setPublic(true);
        $container->register(FrameworkTelemetry::class, FrameworkTelemetry::class);
        $container->setAlias(CacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(TaggedCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(AtomicCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setParameter('vortos.metrics.disabled_modules', []);
        return $container;
    }
}
