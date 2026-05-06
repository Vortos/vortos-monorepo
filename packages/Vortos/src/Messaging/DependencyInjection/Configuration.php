<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_messaging');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('driver')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('producer')
                            ->defaultValue(InMemoryProducer::class)
                        ->end()
                        ->scalarNode('consumer')
                            ->defaultValue(InMemoryConsumer::class)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
