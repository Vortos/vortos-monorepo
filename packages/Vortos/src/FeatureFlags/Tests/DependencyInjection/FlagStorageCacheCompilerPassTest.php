<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FlagStorageCacheCompilerPass;
use Vortos\FeatureFlags\Storage\RedisCachingStorage;

final class FlagStorageCacheCompilerPassTest extends TestCase
{
    public function test_patches_cache_and_redis_when_both_present(): void
    {
        $container = $this->makeContainer();
        $container->setDefinition(CacheInterface::class, new Definition(\stdClass::class));
        $container->setDefinition(\Redis::class, new Definition(\Redis::class));

        (new FlagStorageCacheCompilerPass())->process($container);

        $definition = $container->getDefinition(RedisCachingStorage::class);
        $this->assertSame(CacheInterface::class, (string) $definition->getArgument(1));
        $this->assertSame(\Redis::class, (string) $definition->getArgument(4));
    }

    public function test_leaves_nulls_when_cache_absent(): void
    {
        $container = $this->makeContainer();

        (new FlagStorageCacheCompilerPass())->process($container);

        $definition = $container->getDefinition(RedisCachingStorage::class);
        $this->assertNull($definition->getArgument(1));
        $this->assertNull($definition->getArgument(4));
    }

    public function test_patches_only_cache_when_redis_absent(): void
    {
        $container = $this->makeContainer();
        $container->setDefinition(CacheInterface::class, new Definition(\stdClass::class));

        (new FlagStorageCacheCompilerPass())->process($container);

        $definition = $container->getDefinition(RedisCachingStorage::class);
        $this->assertSame(CacheInterface::class, (string) $definition->getArgument(1));
        $this->assertNull($definition->getArgument(4));
    }

    public function test_no_op_when_storage_absent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(CacheInterface::class, new Definition(\stdClass::class));

        (new FlagStorageCacheCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(RedisCachingStorage::class));
    }

    private function makeContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RedisCachingStorage::class, RedisCachingStorage::class)
            ->setArguments([new Definition(\stdClass::class), null, 60, 'default', null]);
        return $container;
    }
}
