<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\FeatureFlags\Storage\RedisCachingStorage;

/**
 * Patches the $cache (and $redis) arguments of {@see RedisCachingStorage} when a
 * PSR-16 cache / Redis are available.
 *
 * This lives in a compiler pass because a has(CacheInterface::class) /
 * has(\Redis::class) check inside FeatureFlagsExtension::load() runs against the
 * isolated per-extension merge container, where those services (registered by
 * CacheExtension/AuthExtension::load) are never visible — so the refs were always
 * null and feature flags were silently never cached. A compiler pass sees the
 * fully merged container.
 */
final class FlagStorageCacheCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RedisCachingStorage::class)) {
            return;
        }

        $definition = $container->getDefinition(RedisCachingStorage::class);

        if ($container->has(CacheInterface::class)) {
            // Argument index 1 ($cache).
            $definition->setArgument(1, new Reference(CacheInterface::class));
        }

        if ($container->has(\Redis::class)) {
            // Argument index 4 ($redis).
            $definition->setArgument(4, new Reference(\Redis::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
    }
}
