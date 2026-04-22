<?php
declare(strict_types=1);
namespace Vortos\Cache\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Vortos\Cache\Adapter\RedisAdapter;

/**
 * Validates the vortos_cache configuration tree.
 *
 * All nodes have defaults — no config file is required.
 * Root node alias must match CacheExtension::getAlias(): 'vortos_cache'
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_cache');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('driver')
                    ->defaultValue(RedisAdapter::class)
                    ->info('FQCN of TaggedCacheInterface implementation')
                ->end()
                ->scalarNode('dsn')
                    ->defaultValue('redis://redis:6379')
                    ->info('Connection DSN for the cache driver')
                ->end()
                ->scalarNode('prefix')
                    ->defaultValue('vortos_')
                    ->info('Key prefix applied to all cache keys. Include APP_ENV to prevent collisions.')
                ->end()
                ->integerNode('default_ttl')
                    ->defaultValue(3600)
                    ->info('Default TTL in seconds for keys stored without explicit TTL')
                ->end()
            ->end();

        return $treeBuilder;
    }
}