<?php
declare(strict_types=1);
namespace Vortos\Persistence\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validates the vortos_persistence configuration tree.
 *
 * Called by PersistenceExtension::load() via processConfiguration().
 * DSNs are read from VORTOS_* env by VortosPersistenceConfig.
 * Adapter packages validate the specific DSN they require when loaded.
 *
 * The root node alias must match PersistenceExtension::getAlias()
 * exactly — 'vortos_persistence'. A mismatch causes a Symfony DI error
 * at container compile time.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_persistence');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('write')
                    ->children()
                        ->scalarNode('dsn')
                            ->defaultValue('')
                            ->info('Write database DSN')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('read')
                    ->children()
                        ->scalarNode('dsn')
                            ->defaultValue('')
                            ->info('Read database DSN')
                        ->end()
                        ->scalarNode('database')
                            ->defaultValue('')
                            ->info('Read database name')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
