<?php

declare(strict_types=1);

namespace Vortos\Cache\Tracing;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wraps the active cache adapter with TracingCacheAdapter when tracing is enabled.
 *
 * Runs after all extensions load — at that point TracingInterface is guaranteed
 * to be defined (defaults to NoOpTracer). Both CacheInterface and
 * TaggedCacheInterface aliases are re-pointed to TracingCacheAdapter.
 *
 * When TracingModule::Cache is disabled via VortosTracingConfig, ModuleAwareTracer
 * returns NoOpSpan — the decorator becomes effectively zero-overhead.
 */
final class CacheTracingCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(TracingInterface::class) && !$container->hasDefinition(TracingInterface::class)) {
            return;
        }

        // Find which concrete adapter the TaggedCacheInterface currently points to
        if (!$container->hasAlias(TaggedCacheInterface::class)) {
            return;
        }

        $innerServiceId = (string) $container->getAlias(TaggedCacheInterface::class);

        // Register the tracing decorator wrapping the active adapter
        $container->register(TracingCacheAdapter::class, TracingCacheAdapter::class)
            ->setArguments([
                new Reference($innerServiceId),
                new Reference(TracingInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        // Re-point both aliases to the decorator
        $container->setAlias(CacheInterface::class, TracingCacheAdapter::class)
            ->setPublic(true);

        $container->setAlias(TaggedCacheInterface::class, TracingCacheAdapter::class)
            ->setPublic(true);
    }
}
