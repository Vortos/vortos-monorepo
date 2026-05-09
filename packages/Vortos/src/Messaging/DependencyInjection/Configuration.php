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
                ->arrayNode('outbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table')
                            ->defaultValue('vortos_outbox')
                            ->info('Outbox table name. Must match the table created by vortos:setup:messaging.')
                        ->end()
                        ->integerNode('max_attempts')
                            ->defaultValue(5)
                            ->info('Max relay attempts before a message is permanently failed.')
                        ->end()
                        ->integerNode('backoff_base')
                            ->defaultValue(30)
                            ->info('Initial backoff in seconds. Doubles each attempt.')
                        ->end()
                        ->integerNode('backoff_cap')
                            ->defaultValue(3600)
                            ->info('Maximum backoff in seconds.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dlq')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table')
                            ->defaultValue('vortos_failed_messages')
                            ->info('Consumer DLQ table name. Must match the table created by vortos:setup:messaging.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('consumer_defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('idempotency_ttl')
                            ->defaultValue(86400)
                            ->info('Dedup window in seconds. Per-consumer idempotencyTtl() overrides this.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
