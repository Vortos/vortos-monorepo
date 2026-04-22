<?php

declare(strict_types=1);

namespace Vortos\Cache\DependencyInjection;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Cache\Command\CacheClearCommand;
use Vortos\Cache\Command\CacheWarmupCommand;
use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * Wires all cache services.
 *
 * Loads config/cache.php then config/{env}/cache.php (env overrides base).
 *
 * ## Services registered
 *
 *   \Redis                  — shared, built via RedisConnectionFactory::fromDsn()
 *                             Only registered when driver is RedisAdapter.
 *                             Skipped for InMemory/Array to avoid Redis connection on boot.
 *   RedisAdapter            — shared, always registered (used as default driver)
 *   InMemoryAdapter         — shared, always registered (injected by class in tests)
 *   ArrayAdapter            — shared, public (Runner::cleanUp() clears it per request)
 *   CacheInterface          — alias → configured driver
 *   TaggedCacheInterface    — alias → configured driver
 *   CacheClearCommand       — console command
 *   CacheWarmupCommand      — console command with tagged iterator
 *
 * ## Driver swap
 *
 * Both CacheInterface and TaggedCacheInterface aliases point to the configured driver.
 * Swapping driver in config/cache.php automatically routes all injections.
 * RedisAdapter, InMemoryAdapter, ArrayAdapter are always injectable by class name
 * regardless of which driver is active.
 *
 * ## ArrayAdapter lifecycle
 *
 * ArrayAdapter is registered as public. Its service ID is stored as parameter
 * 'vortos.cache.array_adapter_class' so Runner::cleanUp() can fetch and clear it
 * without hardcoding a class name.
 */
final class CacheExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_cache';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $config = new VortosCacheConfig();

        $base = $projectDir . '/config/cache.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/cache.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        // Only register Redis connection when the active driver needs it
        if ($resolved['driver'] === RedisAdapter::class) {
            $container->register(\Redis::class, \Redis::class)
                ->setFactory([RedisConnectionFactory::class, 'fromDsn'])
                ->setArguments([$resolved['dsn']])
                ->setShared(true)
                ->setPublic(false);

            $container->register(RedisAdapter::class, RedisAdapter::class)
                ->setArguments([
                    new Reference(\Redis::class),
                    $resolved['prefix'],
                    $resolved['default_ttl'],
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        // InMemoryAdapter — always registered, injectable by class name in tests
        $container->register(InMemoryAdapter::class, InMemoryAdapter::class)
            ->setShared(true)
            ->setPublic(false);

        // ArrayAdapter — always registered and public (Runner::cleanUp() needs it)
        $container->register(ArrayAdapter::class, ArrayAdapter::class)
            ->setShared(true)
            ->setPublic(true);

        // Store ArrayAdapter class name as parameter for Runner::cleanUp()
        $container->setParameter('vortos.cache.array_adapter_class', ArrayAdapter::class);

        // Alias both PSR-16 and TaggedCacheInterface to the configured driver
        $container->setAlias(CacheInterface::class, $resolved['driver'])
            ->setPublic(true);

        $container->setAlias(TaggedCacheInterface::class, $resolved['driver'])
            ->setPublic(true);

        // Commands
        $container->register(CacheClearCommand::class, CacheClearCommand::class)
            ->setArgument('$cache', new Reference(TaggedCacheInterface::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(CacheWarmupCommand::class, CacheWarmupCommand::class)
            ->setArgument('$warmers', new TaggedIteratorArgument('vortos.cache_warmer'))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
