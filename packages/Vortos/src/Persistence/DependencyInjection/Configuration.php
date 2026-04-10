<?php
declare(strict_types=1);
namespace Vortos\Persistence\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validates the vortos_persistence configuration tree.
 *
 * Called by PersistenceExtension::load() via processConfiguration().
 * All values are required — no sensible defaults for connection strings.
 * Failing loudly on missing config is better than silently connecting
 * to a wrong or empty host.
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
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('PostgreSQL DSN — pgsql://user:pass@host:port/dbname')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('read')
                    ->children()
                        ->scalarNode('dsn')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('MongoDB DSN — mongodb://user:pass@host:port')
                        ->end()
                        ->scalarNode('database')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('MongoDB database name')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}