<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection;

use Fortizan\Tekton\Messaging\Driver\Kafka\Runtime\KafkaProducer;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tekton_messaging');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('driver')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('producer')
                            ->defaultValue(KafkaProducer::class)
                        ->end()
                        ->scalarNode('consumer')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}