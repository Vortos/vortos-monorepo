<?php

declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Cache\Tracing\CacheTracingCompilerPass;
use Vortos\Cache\Tracing\TracingCacheAdapter;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpTracer;

final class CacheTracingCompilerPassTest extends TestCase
{
    public function test_re_aliases_all_three_interfaces_to_tracing_adapter(): void
    {
        $container = $this->makeContainerWithAdapter();

        (new CacheTracingCompilerPass())->process($container);

        $this->assertSame(TracingCacheAdapter::class, (string) $container->getAlias(CacheInterface::class));
        $this->assertSame(TracingCacheAdapter::class, (string) $container->getAlias(TaggedCacheInterface::class));
        $this->assertSame(TracingCacheAdapter::class, (string) $container->getAlias(AtomicCacheInterface::class));
    }

    public function test_does_nothing_when_tracing_interface_not_registered(): void
    {
        $container = new ContainerBuilder();
        $container->register(InMemoryAdapter::class, InMemoryAdapter::class);
        $container->setAlias(TaggedCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(AtomicCacheInterface::class, InMemoryAdapter::class)->setPublic(true);

        (new CacheTracingCompilerPass())->process($container);

        $this->assertSame(InMemoryAdapter::class, (string) $container->getAlias(AtomicCacheInterface::class));
        $this->assertFalse($container->hasDefinition(TracingCacheAdapter::class));
    }

    public function test_does_nothing_when_tagged_cache_alias_not_registered(): void
    {
        $container = new ContainerBuilder();
        $container->register(NoOpTracer::class, NoOpTracer::class);
        $container->setAlias(TracingInterface::class, NoOpTracer::class)->setPublic(true);

        (new CacheTracingCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(TracingCacheAdapter::class));
    }

    public function test_atomic_alias_tracks_through_decorator_chain(): void
    {
        $container = $this->makeContainerWithAdapter();

        (new CacheTracingCompilerPass())->process($container);

        $atomicAlias = (string) $container->getAlias(AtomicCacheInterface::class);
        $this->assertSame(TracingCacheAdapter::class, $atomicAlias);

        $definition = $container->getDefinition(TracingCacheAdapter::class);
        $innerRef = (string) $definition->getArgument(0);
        $this->assertSame(InMemoryAdapter::class, $innerRef);
    }

    private function makeContainerWithAdapter(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(InMemoryAdapter::class, InMemoryAdapter::class);
        $container->register(NoOpTracer::class, NoOpTracer::class);
        $container->setAlias(CacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(TaggedCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(AtomicCacheInterface::class, InMemoryAdapter::class)->setPublic(true);
        $container->setAlias(TracingInterface::class, NoOpTracer::class)->setPublic(true);
        return $container;
    }
}
