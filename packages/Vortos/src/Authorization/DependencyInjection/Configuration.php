<?php
declare(strict_types=1);
namespace Vortos\Authorization\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_authorization');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('role_hierarchy')
                    ->useAttributeAsKey('role')
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                    ->defaultValue([])
                    ->info('Role inheritance map. PARENT_ROLE => [CHILD_ROLE_1, CHILD_ROLE_2]')
                ->end()
            ->end();

        return $treeBuilder;
    }
}